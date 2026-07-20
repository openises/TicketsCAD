<?php
/**
 * Phase B (messaging-send-gaps-2026-06) — unit/person → transport-address
 * resolver.
 *
 * One reusable helper that turns "send to this unit/person" into the
 * transport-specific address a send needs:
 *
 *   - meshtastic → the node_id   (e.g. "!a2a79f57")  from member_comm_identifiers
 *   - meshcore   → the pubkey_prefix (hex >=12 chars) from member_comm_identifiers
 *   - zello      → the username                       from member_comm_identifiers
 *
 * The addresses all live in `member_comm_identifiers.values_json`, keyed
 * by the field key the matching `comm_modes` row defines:
 *
 *   comm_modes.code   values_json key
 *   ---------------   ---------------
 *   meshtastic        node_id
 *   meshcore          pubkey_prefix
 *   zello             username
 *
 * Two linkages map a "unit" (responder row) back to a member:
 *
 *   1. unit_personnel_assignments (responder_id ↔ member_id), the
 *      many-person model — an active, non-released assignment.
 *   2. responder.personal_for_member_id, the one-person "personal unit"
 *      model (inc/personnel-units.php).
 *
 * resolve_unit_address() accepts EITHER a member id or a responder
 * (unit) id and tries both linkages, so callers don't have to know
 * which kind of id they hold.
 *
 * Returns the address string, or null when the person/unit has no
 * identifier of that transport on file (graceful — the caller decides
 * what to do with an unmapped target). Prefers is_primary, then the
 * per-member sort_order, then id.
 *
 * No schema changes — reads existing tables only.
 */

if (!function_exists('db_query')) {
    require_once __DIR__ . '/db.php';
}

/**
 * Map a transport/code to the values_json field key that holds its
 * address. Returns null for an unknown transport.
 */
function comm_resolve_field_key(string $transport): ?string
{
    static $map = [
        'meshtastic' => 'node_id',
        'meshcore'   => 'pubkey_prefix',
        'zello'      => 'username',
    ];
    $transport = strtolower(trim($transport));
    return $map[$transport] ?? null;
}

/**
 * Does member_comm_identifiers have a `sort_order` column on this install?
 * Cached per-process. Defensive: a failed probe returns false (omit the
 * column from ORDER BY) rather than risking a broken query.
 */
function _comm_resolve_has_sort_order(string $prefix): bool
{
    static $has = null;
    if ($has !== null) return $has;
    try {
        $cols = db_fetch_all("SHOW COLUMNS FROM `{$prefix}member_comm_identifiers` LIKE 'sort_order'");
        $has = !empty($cols);
    } catch (Exception $e) {
        $has = false;
    }
    return $has;
}

/**
 * Resolve a MEMBER's address on a given transport.
 *
 * @param int    $memberId
 * @param string $transport  meshtastic | meshcore | zello
 * @return string|null  The address, or null if none on file.
 */
function comm_resolve_member_address(int $memberId, string $transport): ?string
{
    if ($memberId <= 0) return null;
    $code = strtolower(trim($transport));
    $fieldKey = comm_resolve_field_key($code);
    if ($fieldKey === null) return null;

    $prefix = $GLOBALS['db_prefix'] ?? '';

    // The `sort_order` column on member_comm_identifiers is self-healed
    // at runtime by api/comm-identifiers.php and isn't in the base schema,
    // so it may be absent on a given install. Reference it in ORDER BY
    // only when it exists — otherwise the whole query 1054-errors and we
    // lose the address. Detect once per process.
    $orderBy = "mci.is_primary DESC, mci.id";
    if (_comm_resolve_has_sort_order($prefix)) {
        $orderBy = "mci.is_primary DESC, COALESCE(NULLIF(mci.sort_order, 0), mci.id), mci.id";
    }

    try {
        // All identifiers of this transport for the member, best first:
        // is_primary wins, then (when present) the per-member sort_order
        // (same ordering rule the comm-identifiers list uses), then id.
        $rows = db_fetch_all(
            "SELECT mci.values_json
               FROM `{$prefix}member_comm_identifiers` mci
               JOIN `{$prefix}comm_modes` cm ON cm.id = mci.comm_mode_id
              WHERE mci.member_id = ?
                AND cm.code = ?
                AND cm.enabled = 1
              ORDER BY {$orderBy}",
            [$memberId, $code]
        );
    } catch (Exception $e) {
        return null;
    }

    foreach ($rows as $r) {
        $vals = json_decode($r['values_json'] ?? '{}', true);
        if (!is_array($vals)) continue;
        $addr = trim((string) ($vals[$fieldKey] ?? ''));
        if ($addr !== '') return $addr;
    }
    return null;
}

/**
 * Map a responder (unit) id back to a member id, trying both linkages.
 *
 *   1. An active, non-released unit_personnel_assignments row (most
 *      recent assignment wins). For multi-person units this returns
 *      the first/most-recent assigned member.
 *   2. responder.personal_for_member_id (personal-unit model).
 *
 * @return int|null
 */
function comm_resolve_responder_member_id(int $responderId): ?int
{
    if ($responderId <= 0) return null;
    $prefix = $GLOBALS['db_prefix'] ?? '';

    // 1. Active personnel assignment.
    try {
        $mid = db_fetch_value(
            "SELECT member_id
               FROM `{$prefix}unit_personnel_assignments`
              WHERE responder_id = ?
                AND status = 'active'
                AND released_at IS NULL
              ORDER BY assigned_at DESC, id DESC
              LIMIT 1",
            [$responderId]
        );
        if ($mid !== false && $mid !== null && (int) $mid > 0) {
            return (int) $mid;
        }
    } catch (Exception $e) {
        // table may not exist on older installs — fall through
    }

    // 2. Personal-unit linkage.
    try {
        $mid = db_fetch_value(
            "SELECT personal_for_member_id
               FROM `{$prefix}responder`
              WHERE id = ?
              LIMIT 1",
            [$responderId]
        );
        if ($mid !== false && $mid !== null && (int) $mid > 0) {
            return (int) $mid;
        }
    } catch (Exception $e) {
        // personal_for_member_id column may not exist yet — null
    }

    return null;
}

/**
 * Resolve a RESPONDER's (unit's) address on a transport by first mapping
 * the unit to a member, then resolving that member's identifier.
 *
 * @return string|null
 */
function comm_resolve_unit_address_by_responder(int $responderId, string $transport): ?string
{
    $memberId = comm_resolve_responder_member_id($responderId);
    if ($memberId === null) return null;
    return comm_resolve_member_address($memberId, $transport);
}

/**
 * THE reusable entry point. Resolve a unit-or-person → transport address.
 *
 * Accepts either a member id or a responder (unit) id. The caller passes
 * whichever it has; if you know specifically which, the more direct
 * helpers above are clearer. Resolution order when the kind is ambiguous:
 *
 *   1. Treat $id as a member id and look for an identifier directly.
 *   2. If that misses, treat $id as a responder id, map unit→member via
 *      either linkage, and resolve.
 *
 * Pass $kind = 'member' or 'responder'/'unit' to skip the ambiguity and
 * resolve exactly one way (recommended when the caller knows the kind).
 *
 * @param int    $memberOrResponderId
 * @param string $transport  meshtastic | meshcore | zello
 * @param string $kind       'auto' (default) | 'member' | 'responder' | 'unit'
 * @return string|null  Transport address, or null if unmapped.
 */
function resolve_unit_address(int $memberOrResponderId, string $transport, string $kind = 'auto'): ?string
{
    if ($memberOrResponderId <= 0) return null;
    if (comm_resolve_field_key($transport) === null) return null;

    $kind = strtolower(trim($kind));

    if ($kind === 'member') {
        return comm_resolve_member_address($memberOrResponderId, $transport);
    }
    if ($kind === 'responder' || $kind === 'unit') {
        return comm_resolve_unit_address_by_responder($memberOrResponderId, $transport);
    }

    // auto: members are the authoritative identifier owner, so try the
    // member interpretation first, then fall back to the unit linkage.
    $addr = comm_resolve_member_address($memberOrResponderId, $transport);
    if ($addr !== null) return $addr;
    return comm_resolve_unit_address_by_responder($memberOrResponderId, $transport);
}

/**
 * Phase 111 Slice A (Link 1) — REVERSE identity resolution.
 *
 * The functions above go member → address. This one goes the other way:
 * given an inbound message's transport + raw handle (the sender field a
 * broker/proxy/bridge captured), find the member_id that owns that
 * identifier. It's the load-bearing piece for attributing an inbound
 * Zello/DMR/Meshtastic/APRS report to the right volunteer so it flows into
 * their per-person ICS-214.
 *
 * The address lives in `member_comm_identifiers.values_json`, keyed by the
 * transport's field key:
 *
 *   transport    comm_modes.code   values_json key
 *   ----------   ---------------   ---------------
 *   zello        zello             username
 *   dmr          dmr               radio_id
 *   meshtastic   meshtastic        node_id
 *   aprs         aprs              callsign_ssid
 *
 * Matching is case-insensitive (radio handles/callsigns are compared
 * loosely; "!AABBCC" resolves the same as "!aabbcc", "W1ABC-9" the same
 * as "w1abc-9"). The first matching member (lowest id) wins.
 *
 * Returns null when the handle is unknown OR on any error (graceful — the
 * caller logs the message as unattributed and a dispatcher can attribute
 * it later). Never throws.
 *
 * NOTE: this deliberately does NOT reuse comm_resolve_field_key() — that
 * map covers meshtastic/meshcore/zello (the SEND transports). The reverse
 * lookup adds dmr + aprs, which are RECEIVE-side identifiers, and omits
 * meshcore (no inbound-handle use case yet). Keeping a separate map here
 * avoids widening the send-side map with receive-only keys.
 *
 * @param string $transport  zello | dmr | meshtastic | aprs
 * @param string $handle     Raw sender handle/username/id from the message
 * @return int|null          member_id, or null if unknown / on error
 */
function comm_resolve_member_by_address(string $transport, string $handle): ?int
{
    // Reverse map: transport code → values_json field key.
    static $reverseKeys = [
        'zello'      => 'username',
        'dmr'        => 'radio_id',
        'meshtastic' => 'node_id',
        'aprs'       => 'callsign_ssid',
    ];

    $code = strtolower(trim($transport));
    $fieldKey = $reverseKeys[$code] ?? null;
    if ($fieldKey === null) return null;

    $needle = strtolower(trim($handle));
    if ($needle === '') return null;

    $prefix = $GLOBALS['db_prefix'] ?? '';

    try {
        // Pull every identifier row for this transport, then match the JSON
        // field in PHP (case-insensitive). Doing the JSON extraction in PHP
        // rather than SQL keeps this portable across MariaDB versions that
        // vary in JSON_EXTRACT support and avoids a collation mismatch on
        // the latin1 legacy tables. The identifier set per transport is
        // small (one row per member/device), so this is cheap.
        $rows = db_fetch_all(
            "SELECT mci.member_id, mci.values_json
               FROM `{$prefix}member_comm_identifiers` mci
               JOIN `{$prefix}comm_modes` cm ON cm.id = mci.comm_mode_id
              WHERE cm.code = ?
                AND cm.enabled = 1
              ORDER BY mci.member_id ASC, mci.id ASC",
            [$code]
        );
    } catch (Exception $e) {
        return null;
    }

    foreach ($rows as $r) {
        $vals = json_decode($r['values_json'] ?? '{}', true);
        if (!is_array($vals)) continue;
        $stored = strtolower(trim((string) ($vals[$fieldKey] ?? '')));
        if ($stored !== '' && $stored === $needle) {
            $mid = (int) ($r['member_id'] ?? 0);
            if ($mid > 0) return $mid;
        }
    }

    return null;
}
