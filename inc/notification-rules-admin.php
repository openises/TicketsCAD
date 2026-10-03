<?php
/**
 * Phase 155 (GH#144) - the administration of Notification Rules: validation,
 * create / update / delete / toggle / duplicate, the read views the panel needs
 * (list, meta, delivery log, queue), Preview and Test-send, and the three
 * delivery settings.
 *
 * It follows the project's `*_internal` convention (inc/incident-write.php,
 * inc/email-list-write.php): the SQL and the business rules live HERE so a test
 * can drive the real function, and api/notification-rules.php is auth + CSRF +
 * RBAC + JSON shaping only.
 *
 * Every mutating function returns
 *     ['ok' => bool, 'code' => ?string, 'message' => ?string, 'warnings' => string[], ...]
 * and never throws. A failure carries a stable `code` the API maps to a status.
 *
 * WHAT A RULE IS ALLOWED TO BE (validation)
 *   event_type     one of notification_event_ids()
 *   channel        a registered broker channel, or 'all' (accepted so a legacy
 *                  hand-made row can still be edited; the panel never offers it).
 *                  The radio channels (aprs, dmr, meshtastic, meshcore) are NOT
 *                  offered by the panel - they need a sub-address a rule has no
 *                  field for - use Message Routing for them.
 *   recipients     canonical strings user:ID, email:ADDRESS, tel:NUMBER, each
 *                  matching what the channel can address (an SMS rule cannot
 *                  have an email address; a chat or push rule needs user
 *                  accounts; Slack/Telegram take none - they post to the one
 *                  channel configured in Settings)
 *   email_list_id  only for email / smtp; the list must exist and not be archived
 *   filters        severity must be a configured value; incident type must exist.
 *                  Ignored (stored NULL) for an event that is not about an incident.
 *   limits         <= 200 rules, <= 100 recipients, name 1-100, subject <= 255
 *                  on ONE line, body <= 4000
 *
 * Saving is allowed even when the channel is not configured yet or a named user
 * has no phone on file: those come back as WARNINGS, because an administrator
 * may be setting things up in the other order.
 */

require_once __DIR__ . '/notification-engine.php';   // pulls the broker + every channel adapter
require_once __DIR__ . '/email-lists.php';
if (!function_exists('audit_log') && is_file(__DIR__ . '/audit.php')) {
    require_once __DIR__ . '/audit.php';
}

if (!defined('NOTIFICATION_RULES_MAX'))      define('NOTIFICATION_RULES_MAX', 200);
if (!defined('NOTIFICATION_RECIPIENTS_MAX')) define('NOTIFICATION_RECIPIENTS_MAX', 100);
if (!defined('NOTIFICATION_BODY_TEMPLATE_MAX')) define('NOTIFICATION_BODY_TEMPLATE_MAX', 4000);
/** Test-sends allowed per user per window (seconds) - a typo must not become a paging storm. */
if (!defined('NOTIFICATION_TEST_THROTTLE_COUNT'))  define('NOTIFICATION_TEST_THROTTLE_COUNT', 5);
if (!defined('NOTIFICATION_TEST_THROTTLE_WINDOW')) define('NOTIFICATION_TEST_THROTTLE_WINDOW', 300);

function _nra_fail(string $code, string $message, array $extra = []): array
{
    return array_merge(['ok' => false, 'code' => $code, 'message' => $message, 'warnings' => []], $extra);
}

/** audit_log() must never break the action it records. */
function _nra_audit(string $activity, $targetId, string $summary, array $details = [], int $severity = 1): void
{
    try {
        if (function_exists('audit_log')) {
            audit_log('config', 'notification_rule.' . $activity, 'notification_rule', $targetId, $summary, $details, $severity);
        }
    } catch (\Throwable $e) {
        error_log('[notification-rules-admin] audit failed: ' . $e->getMessage());
    }
}

function _nra_sse(string $action, $id): void
{
    try {
        if (!function_exists('sse_publish_for_admin') && is_file(__DIR__ . '/sse.php')) require_once __DIR__ . '/sse.php';
        if (function_exists('sse_publish_for_admin')) {
            sse_publish_for_admin('notification_rules:changed', ['action' => $action, 'id' => $id]);
        }
    } catch (\Throwable $e) { /* admin-only freshness nudge */ }
}

function _nra_db_fail(\Throwable $e, string $tag): array
{
    error_log('[notification-rules-admin] ' . $tag . ': ' . $e->getMessage());
    if (strpos($e->getMessage(), '42S02') !== false) {
        return _nra_fail('tables_missing', 'Notification tables are missing - run php sql/run_migrations.php');
    }
    return _nra_fail('db_error', 'The database could not complete that request.');
}

// ─────────────────────────────────────────────────────────────────────────
// Channels
// ─────────────────────────────────────────────────────────────────────────

/** Channels the panel never offers: they need a sub-address a rule cannot hold. */
function notification_rule_radio_channels(): array
{
    return ['aprs', 'dmr', 'meshtastic', 'meshcore'];
}

/**
 * The channels a rule may use, with how each is addressed and whether it is set
 * up. `configured` is a judgement from the adapter's own status() - a channel
 * that is not configured can still be chosen (warned), because the order an
 * administrator sets things up in is theirs.
 *
 * @return array<int,array{code:string,name:string,kind:string,shared:bool,configured:bool,status:string,tab:string}>
 */
function notification_rule_channels(): array
{
    global $_broker_channels;
    $tabs = ['email' => 'email-config', 'smtp' => 'email-config', 'sms' => 'sms-config', 'slack' => 'slack',
             'telegram' => 'telegram', 'push' => 'push-notifications', 'local_chat' => 'chat-settings'];
    $out = [];
    foreach ($_broker_channels as $code => $handler) {
        if (in_array($code, notification_rule_radio_channels(), true)) continue;
        $name = is_callable($handler['name'] ?? null) ? (string) call_user_func($handler['name']) : (string) ($handler['name'] ?? $code);
        $status = 'unknown';
        if (is_callable($handler['status'] ?? null)) {
            try { $status = call_user_func($handler['status']); } catch (\Throwable $e) { $status = 'error'; }
        }
        if (is_array($status)) {                       // push reports ['enabled'=>..,'configured'=>..]
            $configured = !empty($status['enabled']);
            $status = $configured ? 'active' : (!empty($status['configured']) ? 'disabled' : 'not_configured');
        } else {
            $status = (string) $status;
            $configured = in_array($status, ['active', 'configured', 'ok', 'connected'], true);
        }
        $out[] = [
            'code' => (string) $code, 'name' => $name,
            'kind' => notification_channel_kind((string) $code, $_broker_channels),
            'shared' => !empty($handler['shared_destination']),
            'configured' => $configured, 'status' => $status,
            'tab' => $tabs[$code] ?? '',
        ];
    }
    usort($out, static function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
    return $out;
}

// ─────────────────────────────────────────────────────────────────────────
// Validation
// ─────────────────────────────────────────────────────────────────────────

/**
 * Validate and normalise a rule from user input into the columns we store.
 *
 * @param array      $in       name, event_type, channel, severity_filter,
 *                             incident_type_filter, recipients (string[]),
 *                             email_list_id, subject_template, body_template,
 *                             once_per_incident, active
 * @param array|null $existing the stored row when updating (unset keys keep their value)
 * @return array{ok:bool,errors:string[],warnings:string[],fields:array}
 */
function notification_rule_normalize(array $in, ?array $existing = null): array
{
    global $_broker_channels;
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $errors = []; $warnings = [];
    $get = static function (string $k, $default = null) use ($in, $existing) {
        if (array_key_exists($k, $in)) return $in[$k];
        if ($existing !== null && array_key_exists($k, $existing)) return $existing[$k];
        return $default;
    };

    // name
    $name = trim((string) $get('name', ''));
    if ($name === '' || strlen($name) > 100) $errors[] = 'Give the rule a name (1 to 100 characters).';

    // event
    $event = (string) $get('event_type', '');
    $events = notification_events();
    if (!isset($events[$event])) $errors[] = 'Choose an event.';
    $eventDef = $events[$event] ?? ['has_ticket' => true, 'once_capable' => false];

    // channel
    $channel = (string) $get('channel', 'email');
    if ($channel !== 'all' && !isset($_broker_channels[$channel])) {
        $errors[] = 'Unknown channel "' . substr($channel, 0, 30) . '".';
    }
    $kind = ($channel === 'all') ? 'legacy' : notification_channel_kind($channel, $_broker_channels);
    if ($channel !== 'all' && isset($_broker_channels[$channel])) {
        foreach (notification_rule_channels() as $c) {
            if ($c['code'] === $channel && !$c['configured']) {
                // Name the panel the way the settings sidebar does (an internal tab id like
                // "email-config" means nothing to an administrator).
                $labels = ['email-config' => 'Email Configuration', 'sms-config' => 'SMS Configuration', 'slack' => 'Slack',
                           'telegram' => 'Telegram', 'push-notifications' => 'Web Push Notifications', 'chat-settings' => 'Chat Settings'];
                $where = isset($labels[$c['tab']]) ? ' (open ' . $labels[$c['tab']] . ' under Communications and Integrations in Settings)' : '';
                $warnings[] = $c['name'] . ' is not set up yet' . $where . '. The rule is saved, but nothing will be delivered until it is.';
            }
        }
        if (in_array($channel, notification_rule_radio_channels(), true)) {
            $warnings[] = 'Radio and mesh channels cannot be addressed from a rule - use Message Routing for those.';
        }
    }

    // filters (only meaningful for an event that is about an incident)
    $sevF = $get('severity_filter', null);
    $typeF = $get('incident_type_filter', null);
    $sevF = ($sevF === null || $sevF === '') ? null : (int) $sevF;
    $typeF = ($typeF === null || $typeF === '') ? null : (int) $typeF;
    if (empty($eventDef['has_ticket'])) {
        if ($sevF !== null || $typeF !== null) $warnings[] = 'That event is not about an incident, so the severity and incident-type filters were ignored.';
        $sevF = null; $typeF = null;
    } else {
        if ($sevF !== null && !in_array($sevF, severity_valid_values(), true)) $errors[] = 'That severity level does not exist.';
        if ($typeF !== null) {
            try {
                $ok = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}in_types` WHERE `id` = ?", [$typeF]) > 0;
            } catch (\Throwable $e) { $ok = false; }
            if (!$ok) $errors[] = 'That incident type does not exist.';
        }
    }

    // recipients
    $rawRecipients = $get('recipients', []);
    if (is_string($rawRecipients)) {
        $dec = json_decode($rawRecipients, true);
        $rawRecipients = is_array($dec) ? $dec : [];
    }
    if (!is_array($rawRecipients)) $rawRecipients = [];
    $canon = []; $seen = [];
    $userIds = [];
    foreach ($rawRecipients as $raw) {
        $p = _notification_parse_recipient_entry($raw);
        if ($p === null) { $errors[] = 'Recipient "' . (is_scalar($raw) ? substr((string) $raw, 0, 60) : '?') . '" is not a user, an email address or a phone number.'; continue; }
        if ($p['kind'] === 'user')      { $c = 'user:' . $p['user_id']; $userIds[] = $p['user_id']; }
        elseif ($p['kind'] === 'email') { $c = 'email:' . $p['address']; }
        else                            { $c = 'tel:' . $p['address']; }
        $key = strtolower($c);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $canon[] = ['canon' => $c, 'kind' => $p['kind']];
    }
    if (count($canon) > NOTIFICATION_RECIPIENTS_MAX) $errors[] = 'A rule can have at most ' . NOTIFICATION_RECIPIENTS_MAX . ' recipients - use an email list for larger groups.';

    // email list
    $listId = $get('email_list_id', null);
    $listId = ($listId === null || $listId === '' || (int) $listId === 0) ? null : (int) $listId;
    if ($listId !== null) {
        if (!in_array($channel, ['email', 'smtp'], true)) {
            $errors[] = 'An email list can only be used with an email channel.';
        } else {
            try {
                $row = db_fetch_one("SELECT `id`, `name`, `archived_at` FROM `{$prefix}email_lists` WHERE `id` = ?", [$listId]);
            } catch (\Throwable $e) { $row = null; }
            if (!$row) $errors[] = 'That email list does not exist.';
            elseif ($row['archived_at'] !== null) $errors[] = 'The list "' . $row['name'] . '" is archived - restore it or pick another.';
        }
    }

    // recipient kinds must match what the channel can address
    if ($channel !== 'all') {
        foreach ($canon as $c) {
            $bad = null;
            if ($kind === 'shared') { $bad = 'a shared channel posts to the one destination configured in Settings, so it takes no recipients'; }
            elseif ($kind === 'email' && $c['kind'] === 'tel') { $bad = 'an email rule cannot send to the phone number ' . substr($c['canon'], 4); }
            elseif ($kind === 'phone' && $c['kind'] === 'email') { $bad = 'an SMS rule cannot send to the email address ' . substr($c['canon'], 6); }
            elseif ($kind === 'user' && $c['kind'] !== 'user') { $bad = 'this channel needs user accounts, not ' . substr($c['canon'], 0, strpos($c['canon'], ':')) . ' addresses'; }
            if ($bad !== null) { $errors[] = ucfirst($bad) . '.'; break; }
        }
    }

    // named users: exist? have an address for this channel?
    if ($userIds && !$errors) {
        $contacts = notification_user_contacts($userIds);
        foreach (array_unique($userIds) as $uid) {
            if (!isset($contacts[$uid])) { $errors[] = 'User #' . $uid . ' does not exist.'; continue; }
            if ($kind === 'email' && $contacts[$uid]['email'] === '') $warnings[] = $contacts[$uid]['name'] . ' has no email address on file, so nothing will reach them by email.';
            if ($kind === 'phone' && $contacts[$uid]['phone'] === '') $warnings[] = $contacts[$uid]['name'] . ' has no mobile number on file, so nothing will reach them by SMS.';
        }
    }
    if ($kind !== 'shared' && !$canon && $listId === null) {
        $warnings[] = 'This rule has no recipients, so it will never notify anyone.';
    }

    // templates
    $subject = (string) $get('subject_template', '');
    if (preg_match('/[\r\n]/', $subject)) {
        $subject = trim((string) preg_replace('/\s*[\r\n]+\s*/', ' ', $subject));
        $warnings[] = 'The subject must be one line - line breaks were removed.';
    }
    if (strlen($subject) > 255) $errors[] = 'The subject is longer than 255 characters.';
    $body = (string) $get('body_template', '');
    if (strlen($body) > NOTIFICATION_BODY_TEMPLATE_MAX) $errors[] = 'The message is longer than ' . NOTIFICATION_BODY_TEMPLATE_MAX . ' characters.';
    $unknown = [];
    notification_render($subject, [], 'text', $unknown);
    notification_render($body, [], 'text', $unknown);
    foreach (array_unique($unknown) as $u) $warnings[] = ucfirst($u) . '.';

    // flags
    $once = (int) !empty($get('once_per_incident', 0));
    if ($once && empty($eventDef['once_capable'])) {
        $once = 0;
        $warnings[] = '"Only the first time per incident" does not apply to that event and was switched off.';
    }
    $active = (array_key_exists('active', $in) || $existing !== null) ? (int) !empty($get('active', 1)) : 1;

    return [
        'ok' => !$errors, 'errors' => array_values(array_unique($errors)), 'warnings' => array_values(array_unique($warnings)),
        'fields' => [
            'name' => $name, 'event_type' => $event, 'channel' => $channel,
            'severity_filter' => $sevF, 'incident_type_filter' => $typeF,
            'recipients' => array_map(static function ($c) { return $c['canon']; }, $canon),
            'email_list_id' => $listId, 'subject_template' => $subject, 'body_template' => $body,
            'once_per_incident' => $once, 'active' => $active,
        ],
    ];
}

/** Does the live schema support once_per_incident? (a pre-migration install does not) */
function notification_rule_supports_once(): bool
{
    static $has = null;
    if ($has !== null) return $has;
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $has = (bool) db_fetch_value(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'once_per_incident'",
            [$prefix . 'notification_rules']);
    } catch (\Throwable $e) { $has = false; }
    return $has;
}

// ─────────────────────────────────────────────────────────────────────────
// CRUD
// ─────────────────────────────────────────────────────────────────────────

/** A stored row shaped for the browser (recipients decoded). */
function notification_rule_present(array $row): array
{
    $rec = [];
    if (!empty($row['recipients'])) {
        $d = json_decode((string) $row['recipients'], true);
        if (is_array($d)) {
            foreach ($d as $r) {
                $p = _notification_parse_recipient_entry($r);
                if ($p === null) continue;
                $rec[] = $p['kind'] === 'user' ? 'user:' . $p['user_id']
                    : ($p['kind'] === 'email' ? 'email:' . $p['address'] : 'tel:' . $p['address']);
            }
        }
    }
    return [
        'id' => (int) $row['id'], 'name' => (string) $row['name'], 'event_type' => (string) $row['event_type'],
        'severity_filter' => ($row['severity_filter'] === null || $row['severity_filter'] === '') ? null : (int) $row['severity_filter'],
        'incident_type_filter' => ($row['incident_type_filter'] === null || $row['incident_type_filter'] === '') ? null : (int) $row['incident_type_filter'],
        'channel' => (string) $row['channel'], 'recipients' => $rec,
        'email_list_id' => empty($row['email_list_id']) ? null : (int) $row['email_list_id'],
        'subject_template' => (string) ($row['subject_template'] ?? ''), 'body_template' => (string) ($row['body_template'] ?? ''),
        'once_per_incident' => (int) ($row['once_per_incident'] ?? 0), 'active' => (int) $row['active'],
        'created_by' => isset($row['created_by']) ? (int) $row['created_by'] : null,
        'created_at' => (string) ($row['created_at'] ?? ''), 'updated_at' => (string) ($row['updated_at'] ?? ''),
    ];
}

function notification_rule_get(int $id): ?array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $row = db_fetch_one("SELECT * FROM `{$prefix}notification_rules` WHERE `id` = ?", [$id]);
    } catch (\Throwable $e) { return null; }
    return $row ? notification_rule_present($row) : null;
}

function notification_rule_create_internal(array $in, int $userId): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $n = notification_rule_normalize($in, null);
    if (!$n['ok']) return _nra_fail('validation', $n['errors'][0], ['errors' => $n['errors'], 'warnings' => $n['warnings']]);
    $f = $n['fields'];
    try {
        $count = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}notification_rules`");
        if ($count >= NOTIFICATION_RULES_MAX) {
            return _nra_fail('limit', 'You have reached the limit of ' . NOTIFICATION_RULES_MAX . ' rules.');
        }
        // Column => value, so the schema audits can see exactly which columns this
        // writer fills (a bare list of names next to a dynamic INSERT is invisible to them).
        $row = [
            'name' => $f['name'], 'event_type' => $f['event_type'],
            'severity_filter' => $f['severity_filter'], 'incident_type_filter' => $f['incident_type_filter'],
            'channel' => $f['channel'], 'recipients' => json_encode($f['recipients']),
            'email_list_id' => $f['email_list_id'], 'subject_template' => $f['subject_template'],
            'body_template' => $f['body_template'], 'active' => $f['active'],
            'created_by' => $userId > 0 ? $userId : null,
        ];
        if (notification_rule_supports_once()) $row['once_per_incident'] = $f['once_per_incident'];
        db_query("INSERT INTO `{$prefix}notification_rules` (`" . implode('`, `', array_keys($row)) . "`) VALUES ("
                 . implode(', ', array_fill(0, count($row), '?')) . ")", array_values($row));
        $id = (int) db_insert_id();
    } catch (\Throwable $e) {
        return _nra_db_fail($e, 'create');
    }
    _nra_audit('create', $id, "Created notification rule '{$f['name']}'", [
        'event' => $f['event_type'], 'channel' => $f['channel'], 'recipients' => count($f['recipients']),
        'email_list_id' => $f['email_list_id'], 'severity_filter' => $f['severity_filter'],
        'incident_type_filter' => $f['incident_type_filter'], 'active' => $f['active']]);
    _nra_sse('create', $id);
    return ['ok' => true, 'code' => null, 'message' => null, 'warnings' => $n['warnings'],
            'id' => $id, 'rule' => notification_rule_get($id)];
}

function notification_rule_update_internal(int $id, array $in, int $userId): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    if ($id <= 0) return _nra_fail('bad_request', 'id required');
    $before = notification_rule_get($id);
    if (!$before) return _nra_fail('not_found', 'That rule does not exist.');
    $n = notification_rule_normalize($in, $before);
    if (!$n['ok']) return _nra_fail('validation', $n['errors'][0], ['errors' => $n['errors'], 'warnings' => $n['warnings']]);
    $f = $n['fields'];
    try {
        $row = [
            'name' => $f['name'], 'event_type' => $f['event_type'],
            'severity_filter' => $f['severity_filter'], 'incident_type_filter' => $f['incident_type_filter'],
            'channel' => $f['channel'], 'recipients' => json_encode($f['recipients']),
            'email_list_id' => $f['email_list_id'], 'subject_template' => $f['subject_template'],
            'body_template' => $f['body_template'], 'active' => $f['active'],
        ];
        if (notification_rule_supports_once()) $row['once_per_incident'] = $f['once_per_incident'];
        $sets = [];
        foreach (array_keys($row) as $col) $sets[] = '`' . $col . '` = ?';
        $vals = array_values($row);
        $vals[] = $id;
        db_query("UPDATE `{$prefix}notification_rules` SET " . implode(', ', $sets) . " WHERE `id` = ?", $vals);
    } catch (\Throwable $e) {
        return _nra_db_fail($e, 'update');
    }
    $changed = [];
    foreach ($f as $k => $v) {
        $was = $before[$k] ?? null;
        if (json_encode($was) !== json_encode($v)) $changed[$k] = ['from' => $was, 'to' => $v];
    }
    _nra_audit('update', $id, "Updated notification rule '{$f['name']}'", ['changed' => $changed]);
    _nra_sse('update', $id);
    return ['ok' => true, 'code' => null, 'message' => null, 'warnings' => $n['warnings'],
            'id' => $id, 'rule' => notification_rule_get($id)];
}

function notification_rule_delete_internal(int $id, int $userId): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $rule = notification_rule_get($id);
    if (!$rule) return _nra_fail('not_found', 'That rule does not exist.');
    try {
        db_query("DELETE FROM `{$prefix}notification_rules` WHERE `id` = ?", [$id]);
    } catch (\Throwable $e) {
        return _nra_db_fail($e, 'delete');
    }
    // The delivery log rows stay (rule_id keeps pointing at a rule that is gone,
    // which the log viewer shows as "(deleted rule)") - they are the record of
    // what that rule actually sent.
    _nra_audit('delete', $id, "Deleted notification rule '{$rule['name']}'",
        ['event' => $rule['event_type'], 'channel' => $rule['channel']], AUDIT_MEDIUM);
    _nra_sse('delete', $id);
    return ['ok' => true, 'code' => null, 'message' => null, 'warnings' => [], 'id' => $id];
}

function notification_rule_toggle_internal(int $id, ?bool $active, int $userId): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $rule = notification_rule_get($id);
    if (!$rule) return _nra_fail('not_found', 'That rule does not exist.');
    $new = $active === null ? ($rule['active'] ? 0 : 1) : ($active ? 1 : 0);
    try {
        db_query("UPDATE `{$prefix}notification_rules` SET `active` = ? WHERE `id` = ?", [$new, $id]);
    } catch (\Throwable $e) {
        return _nra_db_fail($e, 'toggle');
    }
    _nra_audit('toggle', $id, "Turned notification rule '{$rule['name']}' " . ($new ? 'on' : 'off'),
        ['from' => $rule['active'], 'to' => $new]);
    _nra_sse('toggle', $id);
    return ['ok' => true, 'code' => null, 'message' => null, 'warnings' => [], 'id' => $id, 'active' => $new];
}

/** Copy a rule (switched OFF, name suffixed) so an administrator can adapt it. */
function notification_rule_duplicate_internal(int $id, int $userId): array
{
    $rule = notification_rule_get($id);
    if (!$rule) return _nra_fail('not_found', 'That rule does not exist.');
    $copy = $rule;
    unset($copy['id']);
    $name = substr($rule['name'], 0, 90) . ' (copy)';
    $copy['name'] = $name;
    $copy['active'] = 0;
    $r = notification_rule_create_internal($copy, $userId);
    if (!empty($r['ok'])) _nra_audit('duplicate', $r['id'], "Duplicated notification rule '{$rule['name']}'", ['source_id' => $id]);
    return $r;
}

// ─────────────────────────────────────────────────────────────────────────
// Reads
// ─────────────────────────────────────────────────────────────────────────

/**
 * Every rule, with when it last fired and its 30-day sent/failed counts (ONE
 * grouped query on notification_log, not one per rule).
 */
function notification_rules_list(): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $rows = db_fetch_all("SELECT * FROM `{$prefix}notification_rules` ORDER BY `active` DESC, `name`, `id`");
    } catch (\Throwable $e) {
        return ['ok' => false, 'rules' => []];
    }
    $stats = [];
    try {
        foreach (db_fetch_all(
            "SELECT `rule_id`, MAX(`sent_at`) AS `last_at`,
                    SUM(CASE WHEN `status` = 'sent' AND `sent_at` >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS `sent_30d`,
                    SUM(CASE WHEN `status` = 'failed' AND `sent_at` >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS `failed_30d`
               FROM `{$prefix}notification_log` WHERE `rule_id` IS NOT NULL AND `status` <> 'skipped' GROUP BY `rule_id`") as $s) {
            $stats[(int) $s['rule_id']] = $s;
        }
    } catch (\Throwable $e) { /* log table absent: no stats */ }
    $out = [];
    foreach ($rows as $row) {
        $r = notification_rule_present($row);
        $s = $stats[$r['id']] ?? null;
        $r['last_fired_at'] = $s ? (string) $s['last_at'] : null;
        $r['sent_30d'] = $s ? (int) $s['sent_30d'] : 0;
        $r['failed_30d'] = $s ? (int) $s['failed_30d'] : 0;
        $out[] = $r;
    }
    return ['ok' => true, 'rules' => $out];
}

/** Read the three delivery settings. */
function notification_settings_get(): array
{
    return [
        'email_format'   => notification_setting('notification_email_format'),
        'prefs_mode'     => notification_setting('notification_prefs_mode'),
        'retention_days' => (int) notification_setting('notification_log_retention_days'),
    ];
}

function _nra_write_setting(string $name, string $value): void
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $n = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}settings` WHERE `name` = ?", [$name]);
    if ($n > 0) db_query("UPDATE `{$prefix}settings` SET `value` = ? WHERE `name` = ?", [$value, $name]);
    else db_query("INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES (?, ?)", [$name, $value]);
}

/** Validate + save the delivery settings; audits old -> new. */
function notification_settings_save_internal(array $in, int $userId): array
{
    $old = notification_settings_get();
    $new = $old;
    if (array_key_exists('email_format', $in)) {
        if (!in_array($in['email_format'], ['text', 'html'], true)) return _nra_fail('validation', 'Email format must be text or html.');
        $new['email_format'] = $in['email_format'];
    }
    if (array_key_exists('prefs_mode', $in)) {
        if (!in_array($in['prefs_mode'], ['explicit_only', 'defaults_apply'], true)) return _nra_fail('validation', 'Preference mode must be explicit_only or defaults_apply.');
        $new['prefs_mode'] = $in['prefs_mode'];
    }
    if (array_key_exists('retention_days', $in)) {
        $d = $in['retention_days'];
        if (!is_numeric($d) || (int) $d < 0 || (int) $d > 3650 || (string) (int) $d !== (string) (int) $d) {
            return _nra_fail('validation', 'Keep the log for 0 (forever) to 3650 days.');
        }
        $new['retention_days'] = (int) $d;
    }
    try {
        _nra_write_setting('notification_email_format', $new['email_format']);
        _nra_write_setting('notification_prefs_mode', $new['prefs_mode']);
        _nra_write_setting('notification_log_retention_days', (string) $new['retention_days']);
    } catch (\Throwable $e) {
        return _nra_db_fail($e, 'save_settings');
    }
    $changed = [];
    foreach ($new as $k => $v) if ($old[$k] !== $v) $changed[$k] = ['from' => $old[$k], 'to' => $v];
    _nra_audit('settings', 'settings', 'Changed notification delivery settings', ['changed' => $changed]);
    _nra_sse('settings', 0);
    return ['ok' => true, 'code' => null, 'message' => null, 'warnings' => [], 'settings' => $new];
}

/** The delivery queue's state, for the panel's status strip. */
function notification_queue_status(): array
{
    $depth = notify_queue_depth();
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $mine = 0; $oldest = null;
    try {
        $r = db_fetch_one("SELECT COUNT(*) AS n, MIN(`scheduled_send_at`) AS oldest FROM `{$prefix}pending_routed_messages`
                            WHERE `status` = 'pending' AND `channel` = ?", [NOTIFY_RULE_CHANNEL]);
        $mine = (int) ($r['n'] ?? 0);
        $oldest = $r['oldest'] ?? null;
    } catch (Throwable $e) { error_log("[notification-rules-admin] queue status read failed: " . $e->getMessage()); }
    $age = null;
    if ($oldest) { $ts = strtotime((string) $oldest); if ($ts) $age = max(0, time() - $ts); }
    return [
        'scheduler_live' => notify_scheduler_is_live(),
        'rule_pending' => $mine, 'rule_oldest_age_s' => $age,
        'all_pending' => $depth['pending'], 'all_failed' => $depth['failed'],
        'breakers' => notification_breaker_status(),
    ];
}

/** Everything the panel needs to render its pickers and help, in one call. */
function notification_rules_meta(): array
{
    $events = [];
    foreach (notification_events() as $id => $e) {
        $events[] = [
            'id' => $id, 'label' => $e['label'], 'description' => $e['description'],
            'has_ticket' => $e['has_ticket'], 'once_capable' => $e['once_capable'],
            'default_subject' => $e['subject'], 'default_body' => $e['body'],
        ];
    }
    $types = [];
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        foreach (db_fetch_all("SELECT `id`, `type`, `group` FROM `{$prefix}in_types` ORDER BY `group`, `sort`, `type`") as $t) {
            $types[] = ['id' => (int) $t['id'], 'type' => (string) $t['type'], 'group' => (string) ($t['group'] ?? '')];
        }
    } catch (\Throwable $e) {
        try {
            foreach (db_fetch_all("SELECT `id`, `type` FROM `{$prefix}in_types` ORDER BY `type`") as $t) {
                $types[] = ['id' => (int) $t['id'], 'type' => (string) $t['type'], 'group' => ''];
            }
        } catch (Throwable $e2) { error_log("[notification-rules-admin] incident type read failed: " . $e2->getMessage()); }
    }
    $lists = [];
    require_once __DIR__ . '/email-lists.php';
    $graph = email_list_load_graph(null);
    if (empty($graph['missing_tables'])) {
        $opts = email_list_options();
        foreach ($graph['lists'] as $id => $l) {
            if (!empty($l['archived'])) continue;
            $exp = email_list_expand($graph, $id, ['skip_statuses' => $opts['skip_statuses']]);
            $lists[] = ['id' => $id, 'name' => $l['name'], 'address_count' => $exp['summary']['unique_addresses'],
                        'problem_count' => $exp['summary']['problems']];
        }
        usort($lists, static function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
    }
    $ph = [];
    foreach (notification_placeholders() as $tok => $d) $ph[] = ['token' => $tok, 'description' => $d['description'], 'example' => $d['example']];
    return [
        'events' => $events, 'channels' => notification_rule_channels(),
        'severities' => severity_levels_for_json(), 'incident_types' => $types, 'email_lists' => $lists,
        'placeholders' => $ph, 'presets' => notification_rule_presets(),
        'settings' => notification_settings_get(),
        'supports' => ['once_per_incident' => notification_rule_supports_once()],
        'queue' => notification_queue_status(),
        'limits' => ['rules' => NOTIFICATION_RULES_MAX, 'recipients' => NOTIFICATION_RECIPIENTS_MAX,
                     'body' => NOTIFICATION_BODY_TEMPLATE_MAX],
    ];
}

/**
 * The delivery log, newest first, with the QUEUE row joined on so a delivery
 * that was killed, expired or failed there shows its true state (the log row
 * itself still reads `queued` until something updates it).
 *
 * @param array $f rule_id, status, from (YYYY-MM-DD), to (YYYY-MM-DD), ticket_id
 */
function notification_log_query(array $f, int $limit = 50, int $offset = 0): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $limit = max(1, min(100, $limit));
    $offset = max(0, $offset);
    $where = ['1=1']; $params = [];
    if (!empty($f['rule_id'])) { $where[] = 'l.`rule_id` = ?'; $params[] = (int) $f['rule_id']; }
    if (!empty($f['ticket_id'])) { $where[] = 'l.`ticket_id` = ?'; $params[] = (int) $f['ticket_id']; }
    if (!empty($f['status']) && in_array($f['status'], ['queued', 'sent', 'failed', 'skipped'], true)) {
        $where[] = 'l.`status` = ?'; $params[] = $f['status'];
    }
    if (!empty($f['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $f['from'])) { $where[] = 'l.`sent_at` >= ?'; $params[] = $f['from'] . ' 00:00:00'; }
    if (!empty($f['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $f['to'])) { $where[] = 'l.`sent_at` <= ?'; $params[] = $f['to'] . ' 23:59:59'; }
    $w = implode(' AND ', $where);
    try {
        $total = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}notification_log` l WHERE {$w}", $params);
        $sql = "SELECT l.`id`, l.`rule_id`, r.`name` AS `rule_name`, l.`event_type`, l.`ticket_id`, l.`channel`,
                       l.`recipient`, l.`subject`, l.`body`, l.`status`, l.`error`, l.`sent_at`, l.`queue_id`,
                       q.`status` AS `queue_status`, q.`send_error` AS `queue_error`
                  FROM `{$prefix}notification_log` l
                  LEFT JOIN `{$prefix}notification_rules` r ON r.`id` = l.`rule_id`
                  LEFT JOIN `{$prefix}pending_routed_messages` q ON q.`id` = l.`queue_id`
                 WHERE {$w} ORDER BY l.`id` DESC LIMIT {$limit} OFFSET {$offset}";
        try {
            $rows = db_fetch_all($sql, $params);
        } catch (\Throwable $e) {
            // pre-migration: no queue_id column
            $rows = db_fetch_all(str_replace(['l.`queue_id`,', 'q.`status` AS `queue_status`, q.`send_error` AS `queue_error`', "LEFT JOIN `{$prefix}pending_routed_messages` q ON q.`id` = l.`queue_id`"],
                ['', 'NULL AS `queue_status`, NULL AS `queue_error`', ''], $sql), $params);
        }
    } catch (\Throwable $e) {
        error_log('[notification-rules-admin] log query failed: ' . $e->getMessage());
        return ['ok' => false, 'rows' => [], 'total' => 0];
    }
    $out = [];
    foreach ($rows as $r) {
        $eff = (string) $r['status'];
        $reason = (string) ($r['error'] ?? '');
        if ($eff === 'queued' && in_array((string) $r['queue_status'], ['killed', 'expired', 'failed'], true)) {
            $eff = (string) $r['queue_status'];
            if ($reason === '') $reason = (string) ($r['queue_error'] ?? '');
        }
        $body = (string) ($r['body'] ?? '');
        $out[] = [
            'id' => (int) $r['id'], 'rule_id' => $r['rule_id'] !== null ? (int) $r['rule_id'] : null,
            'rule_name' => $r['rule_name'] !== null ? (string) $r['rule_name'] : null,
            'event_type' => (string) $r['event_type'], 'ticket_id' => $r['ticket_id'] !== null ? (int) $r['ticket_id'] : null,
            'channel' => (string) $r['channel'], 'recipient' => (string) $r['recipient'], 'subject' => (string) $r['subject'],
            'body' => strlen($body) > 2000 ? substr($body, 0, 2000) : $body, 'body_truncated' => strlen($body) > 2000,
            'status' => (string) $r['status'], 'effective_status' => $eff, 'reason' => $reason,
            'sent_at' => (string) $r['sent_at'],
        ];
    }
    return ['ok' => true, 'rows' => $out, 'total' => $total];
}

// ─────────────────────────────────────────────────────────────────────────
// Recipient picker
// ─────────────────────────────────────────────────────────────────────────

/**
 * Find user accounts for the recipient picker (by login, name, or the linked
 * roster member's name / callsign), or look up known ids (to label the chips of a
 * saved rule). It answers whether an account HAS an email address and a mobile
 * number - so the page can warn "this person cannot be reached by SMS" - and
 * never the address or number itself: the page needs the fact, not the data.
 *
 * @param int[] $ids exact ids to look up (max 100) - when given, $q is ignored
 * @return array<int,array{id:int,name:string,login:string,has_email:bool,has_mobile:bool}>
 */
function notification_recipient_users(string $q, array $ids = [], int $limit = 15): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $limit = max(1, min(25, $limit));
    $found = [];
    try {
        if ($ids) {
            $found = array_values(array_unique(array_filter(array_map('intval', array_slice($ids, 0, 100)))));
        } else {
            $q = trim($q);
            if ($q === '') return [];
            // Escape the LIKE wildcards so "a_c" / "50%" search for those characters.
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], substr($q, 0, 60)) . '%';
            $rows = db_fetch_all(
                "SELECT DISTINCT u.`id` FROM `{$prefix}user` u
                   LEFT JOIN `{$prefix}member` m ON m.`user_id` = u.`id`
                  WHERE u.`user` LIKE ? OR u.`name_f` LIKE ? OR u.`name_l` LIKE ?
                     OR m.`first_name` LIKE ? OR m.`last_name` LIKE ? OR m.`callsign` LIKE ?
                  ORDER BY u.`user` LIMIT {$limit}", [$like, $like, $like, $like, $like, $like]);
            foreach ($rows as $r) $found[] = (int) $r['id'];
        }
    } catch (\Throwable $e) {
        error_log('[notification-rules-admin] recipient search failed: ' . $e->getMessage());
        return [];
    }
    if (!$found) return [];
    $contacts = notification_user_contacts($found);
    $logins = [];
    try {
        $in = implode(',', array_fill(0, count($found), '?'));
        foreach (db_fetch_all("SELECT `id`, `user` FROM `{$prefix}user` WHERE `id` IN ({$in})", $found) as $r) {
            $logins[(int) $r['id']] = (string) $r['user'];
        }
    } catch (\Throwable $e) {
        error_log('[notification-rules-admin] recipient login lookup failed: ' . $e->getMessage());
    }
    $out = [];
    foreach ($found as $id) {
        if (!isset($contacts[$id])) continue;
        $out[] = ['id' => $id, 'name' => $contacts[$id]['name'], 'login' => $logins[$id] ?? '',
                  'has_email' => $contacts[$id]['email'] !== '', 'has_mobile' => $contacts[$id]['phone'] !== ''];
    }
    usort($out, static function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
    return $out;
}

// ─────────────────────────────────────────────────────────────────────────
// Preview + test send
// ─────────────────────────────────────────────────────────────────────────

/** The context a Preview / Test renders against. */
function notification_sample_context(string $event, string $sample): array
{
    $events = notification_events();
    $def = $events[$event] ?? null;
    $sampleCtx = $def['sample'] ?? [];
    $extrasOnly = array_intersect_key($sampleCtx, array_flip(
        ['units', 'unit_count', 'responder_name', 'old_status_label', 'new_status_label', 'message_subject', 'message']));
    $tid = 0;
    if ($sample === 'latest' || ctype_digit($sample)) {
        $prefix = $GLOBALS['db_prefix'] ?? '';
        try {
            $tid = ctype_digit($sample) ? (int) $sample
                : (int) db_fetch_value("SELECT `id` FROM `{$prefix}ticket`
                                         WHERE (`deleted_at` IS NULL OR `deleted_at` = '0000-00-00 00:00:00') ORDER BY `id` DESC LIMIT 1");
        } catch (\Throwable $e) { $tid = 0; }
    }
    if ($tid > 0) {
        $ctx = notification_build_context($extrasOnly + ['ticket_id' => $tid, 'event_id' => $event]);
        if (!empty($ctx['incident_type']) || !empty($ctx['scope'])) { $ctx['_sample'] = 'incident #' . $tid; return $ctx; }
    }
    $ctx = $sampleCtx + ['event' => (string) ($def['label'] ?? $event), '_when' => time(), '_synthetic' => true,
                          'user' => (string) ($_SESSION['user'] ?? 'dispatcher1')];
    $ctx['_when'] = time();
    $ctx['_sample'] = 'sample incident';
    return $ctx;
}

/**
 * Show what a rule WOULD do, sending nothing and writing nothing: the rendered
 * text per channel mode, and every delivery it would make with the reason for
 * each one it would skip. It runs the SAME planner a real event does.
 *
 * @param string $sample 'synthetic' | 'latest' | an incident id
 */
function notification_rule_preview(array $in, string $sample = 'synthetic'): array
{
    $n = notification_rule_normalize($in, null);
    $f = $n['fields'];
    if (!isset(notification_events()[$f['event_type']])) {
        return _nra_fail('validation', $n['errors'][0] ?? 'Choose an event.', ['errors' => $n['errors']]);
    }
    $ctx = notification_sample_context($f['event_type'], $sample);
    $rule = ['id' => 0, 'event_type' => $f['event_type'], 'channel' => $f['channel'],
             'recipients' => json_encode($f['recipients']), 'email_list_id' => $f['email_list_id'],
             'subject_template' => $f['subject_template'], 'body_template' => $f['body_template']];
    $plan = notification_plan_rule($rule, $ctx);

    $queued = 0; $skipped = 0; $deliveries = [];
    foreach ($plan['deliveries'] as $d) {
        if ($d['status'] === 'queued') $queued++; else $skipped++;
        $deliveries[] = [
            'channel' => $d['channel'], 'kind' => $d['kind'], 'label' => (string) ($d['label'] ?? ''),
            'address' => (string) $d['address'], 'status' => $d['status'], 'reason' => (string) ($d['reason'] ?? ''),
            'subject' => $d['subject'], 'body' => $d['body'], 'delayed_until' => $d['scheduled_send_at'] ?? null,
        ];
    }
    // The text, even when no delivery was planned (no recipients yet).
    global $_broker_channels;
    $kind = $f['channel'] === 'all' ? 'legacy' : notification_channel_kind($f['channel'], $_broker_channels);
    $html = ($kind === 'email' && notification_setting('notification_email_format') === 'html');
    $subjTpl = $f['subject_template'] !== '' ? $f['subject_template'] : notification_default_subject($f['event_type']);
    $bodyTpl = $f['body_template'] !== '' ? $f['body_template'] : notification_default_body($f['event_type']);
    $warn = $plan['warnings'];
    return ['ok' => true, 'code' => null, 'message' => null,
        'warnings' => array_values(array_unique(array_merge($n['warnings'], $warn, $n['errors']))),
        'sample' => (string) ($ctx['_sample'] ?? ''),
        'rendered' => ['subject' => notification_render_subject($subjTpl, $ctx),
                       'body' => notification_render($bodyTpl, $ctx, $html ? 'html' : 'text'),
                       'format' => $html ? 'html' : 'text'],
        'deliveries' => $deliveries, 'queued' => $queued, 'skipped' => $skipped,
        'recipient_count' => $plan['recipient_count']];
}

/** How many test sends has this user made inside the throttle window? */
function notification_test_send_recent_count(int $userId, ?int $now = null): int
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $cut = date('Y-m-d H:i:s', ($now ?? time()) - NOTIFICATION_TEST_THROTTLE_WINDOW);
    try {
        return (int) db_fetch_value(
            "SELECT COUNT(*) FROM `{$prefix}newui_audit_log`
              WHERE `category` = 'config' AND `activity` = 'notification_rule.test_send'
                AND `user_id` = ? AND `event_time` >= ?", [$userId, $cut]);
    } catch (\Throwable $e) { return 0; }
}

/**
 * Send ONE real test through the chosen channel, synchronously and bounded.
 *
 *   mode 'me'    (default) to the administrator's OWN address on the rule's
 *                channel (email / phone / user account). The safe default: a test
 *                must not page the whole department.
 *   mode 'rule'  to the rule's REAL recipients - requires confirm_real = true,
 *                and the caller is expected to have listed the destinations and
 *                warned that a pager / Active911 address pages real devices.
 *   shared channels (Slack, Telegram) cannot be redirected to "me", so they
 *                always require confirm_real.
 *
 * Subject and body are prefixed [TEST]. Throttled to
 * NOTIFICATION_TEST_THROTTLE_COUNT per NOTIFICATION_TEST_THROTTLE_WINDOW seconds
 * per user. Every send is audited and logged to notification_log.
 *
 * @param array $opts mode (me|rule), confirm_real (bool), sample
 */
function notification_rule_test_send(array $in, array $opts, int $userId): array
{
    global $_broker_channels;
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $mode = ($opts['mode'] ?? 'me') === 'rule' ? 'rule' : 'me';
    $confirm = !empty($opts['confirm_real']);

    $n = notification_rule_normalize($in, null);
    $f = $n['fields'];
    if (!isset(notification_events()[$f['event_type']]) || ($f['channel'] !== 'all' && !isset($_broker_channels[$f['channel']]))) {
        return _nra_fail('validation', $n['errors'][0] ?? 'Choose an event and a channel first.', ['errors' => $n['errors']]);
    }
    if ($f['channel'] === 'all') return _nra_fail('validation', 'Test-send needs one specific channel, not "all".');

    $kind = notification_channel_kind($f['channel'], $_broker_channels);
    if (($kind === 'shared' || $mode === 'rule') && !$confirm) {
        return _nra_fail('confirm_required', $kind === 'shared'
            ? 'This channel posts to one shared destination and cannot be redirected to you - confirm to send the test there.'
            : 'Sending to the rule\'s real recipients needs confirmation - this will reach those people.');
    }
    if (notification_test_send_recent_count($userId) >= NOTIFICATION_TEST_THROTTLE_COUNT) {
        return _nra_fail('throttled', 'That is ' . NOTIFICATION_TEST_THROTTLE_COUNT . ' test sends in '
            . (int) (NOTIFICATION_TEST_THROTTLE_WINDOW / 60) . ' minutes - wait a few minutes before sending more.');
    }

    $ctx = notification_sample_context($f['event_type'], (string) ($opts['sample'] ?? 'synthetic'));
    $rule = ['id' => 0, 'event_type' => $f['event_type'], 'channel' => $f['channel'],
             'recipients' => json_encode($mode === 'me' ? [] : $f['recipients']),
             'email_list_id' => $mode === 'me' ? null : $f['email_list_id'],
             'subject_template' => $f['subject_template'], 'body_template' => $f['body_template']];

    if ($mode === 'me') {
        // One delivery to the admin's own address on this channel.
        $rule['recipients'] = json_encode(['user:' . $userId]);
        if ($kind === 'shared') $rule['recipients'] = json_encode([]);
    }
    $plan = notification_plan_rule($rule, $ctx);

    $sent = []; $failed = 0; $skipped = [];
    $budget = 15.0;
    $t0 = microtime(true);
    foreach ($plan['deliveries'] as $d) {
        if ($d['status'] === 'skipped') { $skipped[] = ['address' => $d['address'], 'reason' => (string) $d['reason']]; continue; }
        if (count($sent) >= 25) { $skipped[] = ['address' => $d['address'], 'reason' => 'test sends are capped at 25 destinations']; continue; }
        $remaining = $budget - (microtime(true) - $t0);
        if ($remaining < 1.0) { $skipped[] = ['address' => $d['address'], 'reason' => 'out of time budget']; continue; }
        $payload = ['channel' => $d['channel'], 'to' => $d['to'], 'user_id' => $d['user_id'],
                    'priority' => 'normal', 'content_type' => $d['content_type']];
        $res = notification_deliver_message($d['channel'],
            notification_delivery_message($payload, '[TEST] ' . $d['subject'], '[TEST] ' . $d['body']), $remaining);
        $logId = notification_log_insert([
            'rule_id' => null, 'event_type' => $f['event_type'], 'ticket_id' => null, 'channel' => $d['channel'],
            'recipient' => $d['address'], 'subject' => '[TEST] ' . $d['subject'], 'body' => '[TEST] ' . $d['body'],
            'status' => $res['success'] ? 'sent' : 'failed', 'error' => $res['success'] ? null : $res['error']]);
        if (!$res['success']) $failed++;
        $sent[] = ['address' => $d['address'], 'ok' => $res['success'], 'error' => $res['success'] ? null : (string) $res['error'], 'log_id' => $logId];
    }
    if (!$sent && !$skipped) {
        return _nra_fail('nothing_to_send', $mode === 'me'
            ? 'There is nothing to send to - this channel has no address for you.'
            : 'The rule has no recipients to send to.');
    }
    if (!$sent) {
        return _nra_fail('nothing_to_send', 'Nothing could be sent: ' . ($skipped[0]['reason'] ?? 'no usable recipient') . '.', ['skipped' => $skipped]);
    }
    _nra_audit('test_send', $f['name'] !== '' ? $f['name'] : 'test', "Test-sent notification rule '" . ($f['name'] ?: '(unsaved)') . "'", [
        'mode' => $mode, 'channel' => $f['channel'], 'event' => $f['event_type'],
        'destinations' => array_map(static function ($s) { return $s['address']; }, $sent), 'failed' => $failed]);
    return ['ok' => true, 'code' => null, 'message' => null, 'warnings' => $n['warnings'],
            'mode' => $mode, 'sent' => $sent, 'failed' => $failed, 'skipped' => $skipped];
}
