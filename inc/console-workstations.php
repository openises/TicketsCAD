<?php
/**
 * Phase 152 (Console rebuild) — workstation identity + adjacent-transmit
 * mute. Business logic behind api/console-workstation-mutes.php (and
 * shared by api/console-session.php's own registration call), matching
 * this project's established split for admin-CRUD-UI endpoints so it can
 * be driven directly by tests.
 *
 * "Nearby workstations" is deliberately a RECENCY list (last_seen_at
 * within NEARBY_WINDOW_SECONDS), not a precise real-time channel-overlap
 * computation. plan.md's own wording says "lists other workstations
 * currently active on shared channels" -- true real-time overlap would
 * need a live GET /routes call to the Python control plane PLUS resolving
 * every browser:<session> route endpoint back to a workstation_id, adding
 * a real network dependency to a page-load list for marginal benefit: the
 * operator already knows who's physically in the room, the list exists to
 * surface the right LABEL/id to pick, not to prove channel overlap. This
 * mirrors the acoustic-discovery sub-feature's own explicit framing
 * ("best-effort convenience... not a guaranteed result") one layer
 * earlier -- a disclosed simplification, not a silent shortfall.
 */

const CONSOLE_WORKSTATION_NEARBY_WINDOW_SECONDS = 900; // 15 minutes

// Acoustic proximity auto-discovery -- the number of distinct 2-of-8
// near-ultrasonic frequency-pair codes assets/js/console-beacon.js's tone
// plan supports (C(8,2) = 28). beacon_code is assigned modulo this range;
// see sql/run_phase152_beacon_discovery.php's own docblock for why a
// collision beyond 28 ever-created workstations is a disclosed, accepted
// limitation rather than an error condition.
const CONSOLE_WORKSTATION_BEACON_CODE_COUNT = 28;

function _console_workstations_prefix() {
    return $GLOBALS['db_prefix'] ?? '';
}

/**
 * Upsert-and-touch: creates the row on first sight of a token, or bumps
 * last_seen_at if it already exists. Called from BOTH api/console-
 * session.php's mint (Prerequisite 3 — fires only once a browser actually
 * engages real matrix audio) AND api/console-workstation-mutes.php itself
 * (so opening the Nearby Workstations panel works even before that,
 * rather than erroring on an unregistered token). Idempotent, side-effect
 * safe either way. Assigns beacon_code on the row's FIRST creation only —
 * never touched again, so a workstation's tone never changes underneath
 * an in-progress or previously-cached discovery result.
 */
function console_workstation_resolve(string $token): array {
    $token = trim($token);
    if ($token === '' || !preg_match('/^[0-9a-fA-F-]{8,36}$/', $token)) {
        throw new Exception('Invalid workstation token');
    }
    $prefix = _console_workstations_prefix();
    db_query(
        "INSERT INTO `{$prefix}console_workstations` (workstation_token, last_seen_at)
         VALUES (?, NOW())
         ON DUPLICATE KEY UPDATE last_seen_at = NOW()",
        [$token]
    );
    db_query(
        "UPDATE `{$prefix}console_workstations`
            SET beacon_code = (id - 1) % ?
          WHERE workstation_token = ? AND beacon_code IS NULL",
        [CONSOLE_WORKSTATION_BEACON_CODE_COUNT, $token]
    );
    return db_fetch_one(
        "SELECT * FROM `{$prefix}console_workstations` WHERE workstation_token = ?",
        [$token]
    );
}

function console_workstation_get_row($id) {
    $prefix = _console_workstations_prefix();
    return db_fetch_one("SELECT * FROM `{$prefix}console_workstations` WHERE id = ?", [(int) $id]);
}

/**
 * Resolves PHP's internal integer id back to the raw workstation_token
 * string -- the identity the live Python matrix actually works with
 * (MatrixCore.set_workstation_mute() takes tokens, never PHP's own
 * auto-increment ids). Null if the id doesn't exist.
 */
function console_workstation_token_for_id(int $id): ?string {
    $prefix = _console_workstations_prefix();
    $token = db_fetch_value("SELECT workstation_token FROM `{$prefix}console_workstations` WHERE id = ?", [$id]);
    return $token !== false && $token !== null ? (string) $token : null;
}

function console_workstation_set_label(int $workstationId, string $label): void {
    $label = trim($label);
    if (mb_strlen($label) > 64) {
        throw new Exception('Label must be 64 characters or fewer');
    }
    $prefix = _console_workstations_prefix();
    db_query(
        "UPDATE `{$prefix}console_workstations` SET label = ? WHERE id = ?",
        [$label === '' ? null : $label, $workstationId]
    );
}

/**
 * Every OTHER workstation seen recently (see the file docblock for why
 * this is a recency list, not a channel-overlap computation), most
 * recently active first.
 */
function console_workstations_nearby(int $excludeWorkstationId): array {
    $prefix = _console_workstations_prefix();
    return db_fetch_all(
        "SELECT id, label, last_seen_at
           FROM `{$prefix}console_workstations`
          WHERE id != ? AND last_seen_at IS NOT NULL
            AND last_seen_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
          ORDER BY last_seen_at DESC",
        [$excludeWorkstationId, CONSOLE_WORKSTATION_NEARBY_WINDOW_SECONDS]
    );
}

/**
 * Resolves a detected beacon_code (from assets/js/console-beacon.js's
 * FFT detection) back to the console_workstations row it belongs to --
 * excluding the caller's own workstation (the initiator never plays or
 * matches its own tone) and any row stale enough to have already fallen
 * out of the recency window console_workstations_nearby() uses, so a
 * beacon match can never resurrect a desk nobody would otherwise see as
 * "nearby." A code collision (CONSOLE_WORKSTATION_BEACON_CODE_COUNT's own
 * docblock explains why one is possible past 28 ever-created rows)
 * resolves to whichever matching row was seen MOST recently -- the more
 * plausible real candidate actually in the room right now.
 */
function console_workstation_resolve_beacon_code(int $beaconCode, int $excludeWorkstationId): ?array {
    $prefix = _console_workstations_prefix();
    return db_fetch_one(
        "SELECT * FROM `{$prefix}console_workstations`
          WHERE beacon_code = ? AND id != ? AND last_seen_at IS NOT NULL
            AND last_seen_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
          ORDER BY last_seen_at DESC LIMIT 1",
        [$beaconCode, $excludeWorkstationId, CONSOLE_WORKSTATION_NEARBY_WINDOW_SECONDS]
    );
}

/** The set of workstation_ids this workstation currently has muted. */
function console_workstation_muted_ids(int $workstationId): array {
    $prefix = _console_workstations_prefix();
    $rows = db_fetch_all(
        "SELECT muted_workstation_id FROM `{$prefix}console_workstation_mutes` WHERE workstation_id = ?",
        [$workstationId]
    );
    return array_map(function ($r) { return (int) $r['muted_workstation_id']; }, $rows);
}

/**
 * Every currently-configured pairing, for an admin-facing "who has muted
 * whom" view (plan.md: "an admin auditing the room can see every current
 * pairing from one place, not just each workstation's own view of
 * itself").
 */
function console_workstation_mutes_all(): array {
    $prefix = _console_workstations_prefix();
    return db_fetch_all(
        "SELECT m.workstation_id, wa.label AS workstation_label,
                m.muted_workstation_id, wb.label AS muted_workstation_label,
                m.created_at
           FROM `{$prefix}console_workstation_mutes` m
           JOIN `{$prefix}console_workstations` wa ON wa.id = m.workstation_id
           JOIN `{$prefix}console_workstations` wb ON wb.id = m.muted_workstation_id
          ORDER BY m.created_at DESC"
    );
}

/**
 * Sets (or clears) a mute pairing in BOTH directions in one call — the
 * mechanism plan.md's manual-entry AND acoustic-discovery sections both
 * describe explicitly ("the UI sets up both directions in one action
 * rather than assuming symmetry is automatic"). The underlying table
 * stays genuinely directional (two independent rows); this is the one
 * function every UI path funnels through so the "always both directions"
 * behavior lives in exactly one place.
 */
function console_workstation_set_mute_pair(int $a, int $b, bool $muted, ?int $createdBy = null): void {
    if ($a === $b) {
        throw new Exception('A workstation cannot mute itself');
    }
    if (!console_workstation_get_row($a) || !console_workstation_get_row($b)) {
        throw new Exception('Unknown workstation');
    }
    $prefix = _console_workstations_prefix();
    if ($muted) {
        db_query(
            "INSERT INTO `{$prefix}console_workstation_mutes` (workstation_id, muted_workstation_id, created_by)
             VALUES (?, ?, ?), (?, ?, ?)
             ON DUPLICATE KEY UPDATE created_at = created_at",
            [$a, $b, $createdBy, $b, $a, $createdBy]
        );
    } else {
        db_query(
            "DELETE FROM `{$prefix}console_workstation_mutes`
              WHERE (workstation_id = ? AND muted_workstation_id = ?)
                 OR (workstation_id = ? AND muted_workstation_id = ?)",
            [$a, $b, $b, $a]
        );
    }
}
