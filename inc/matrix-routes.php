<?php
/**
 * Phase 114c — audio-matrix route (patch) writer, closing SPEC-STATUS.md §B1
 *
 * `comm_routes` (sql/run_phase114c_comm_routes.php) is the audio patch
 * matrix's route table — one row per directed patch, src channel -> dst
 * channel. Before this file the table had a schema and exactly one reader
 * (services/audio-matrix/service.py:124-162, load_routes()) but NO writer
 * anywhere in the application: a patch could only be created by hand-
 * written SQL, and the RBAC permission action.manage_matrix (already
 * seeded to Super Admin/Org Admin by the 114c migration) gated nothing
 * real.
 *
 * Every validation rule here MIRRORS services/audio-matrix/matrix_core.py's
 * MatrixCore.add_route() exactly, on purpose: that Python function is what
 * actually loads these rows into the live matrix at service start, and it
 * silently SKIPS (not errors on) a route that fails its checks — unknown
 * channel, self-route, duplicate src/dst pair, or a regulatory-blocked
 * cross-class pair without the override (matrix_core.py's _BLOCKED_PAIRS).
 * A writer that let an admin create a row the Python service would then
 * silently drop would just be a fancier version of the hand-SQL problem —
 * "no silent routes" is spec.md's own guardrail #2. Keep the two rule sets
 * in sync if either changes; tests/test_matrix_routes.php asserts the pair
 * list matches matrix_core.py's literal source.
 *
 * Functions here are called directly by api/matrix.php AND by
 * tests/test_matrix_routes.php (driving the real writer, not hand-seeded
 * rows — CLAUDE.md's standing test-discipline rule).
 */

if (!function_exists('matrix_blocked_class_pairs')) {

/**
 * Regulatory-class pairs that may never patch to each other without an
 * explicit, audited operator override (FCC Part 97.113: no amateur<->
 * business, amateur<->PSTN autopatch heavily constrained). Mirrors
 * matrix_core.py's `_BLOCKED_PAIRS` literally — internal<->anything is
 * always allowed (dispatch monitoring).
 *
 * @return array<int, array{0:string,1:string}>
 */
function matrix_blocked_class_pairs() {
    return [
        ['amateur', 'commercial'],
        ['amateur', 'pstn'],
    ];
}

/** True if two regulatory classes are a blocked pair (either order). */
function matrix_classes_blocked($a, $b) {
    foreach (matrix_blocked_class_pairs() as $pair) {
        if (($pair[0] === $a && $pair[1] === $b) || ($pair[0] === $b && $pair[1] === $a)) {
            return true;
        }
    }
    return false;
}

/**
 * All routes, joined with their channels' key/label/regulatory_class/
 * enabled state for display. Orphan-tolerant: a route whose channel was
 * pruned by channel_registry_sync() (comm_routes has no hard FK — see the
 * 114c migration's docblock for why) still lists, with NULL channel
 * fields, rather than vanishing or erroring — the admin needs to see and
 * clean up an orphan, not have it silently disappear.
 */
function matrix_routes_all() {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    return db_fetch_all(
        "SELECT r.*,
                sc.channel_key AS src_key, sc.label AS src_label,
                sc.regulatory_class AS src_class, sc.enabled AS src_enabled,
                dc.channel_key AS dst_key, dc.label AS dst_label,
                dc.regulatory_class AS dst_class, dc.enabled AS dst_enabled
           FROM `{$prefix}comm_routes` r
      LEFT JOIN `{$prefix}comm_channels` sc ON sc.id = r.src_channel_id
      LEFT JOIN `{$prefix}comm_channels` dc ON dc.id = r.dst_channel_id
       ORDER BY r.id"
    );
}

/** One route by id, raw columns only (no channel join) — null if absent. */
function matrix_route_get($id) {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    return db_fetch_one("SELECT * FROM `{$prefix}comm_routes` WHERE id = ?", [(int) $id]);
}

/** One route by id, joined with channel display fields — null if absent. */
function matrix_route_full($id) {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    return db_fetch_one(
        "SELECT r.*,
                sc.channel_key AS src_key, sc.label AS src_label,
                sc.regulatory_class AS src_class, sc.enabled AS src_enabled,
                dc.channel_key AS dst_key, dc.label AS dst_label,
                dc.regulatory_class AS dst_class, dc.enabled AS dst_enabled
           FROM `{$prefix}comm_routes` r
      LEFT JOIN `{$prefix}comm_channels` sc ON sc.id = r.src_channel_id
      LEFT JOIN `{$prefix}comm_channels` dc ON dc.id = r.dst_channel_id
          WHERE r.id = ?",
        [(int) $id]
    );
}

/**
 * Validate a proposed (src, dst, allow_cross_class) triple against every
 * rule matrix_core.py's add_route() enforces at load time. Throws
 * InvalidArgumentException with a human-readable message — never a bare
 * DB error — on the first rule broken.
 *
 * @param int      $srcId
 * @param int      $dstId
 * @param bool     $allowCrossClass caller's requested override flag
 * @param int|null $excludeRouteId  when updating, this route's own id (so
 *                                  it doesn't collide with itself in the
 *                                  duplicate check)
 * @return array{src:array,dst:array,cross_class:bool} the resolved channel
 *         rows + whether this pair actually needed the override (so the
 *         caller can store allow_cross_class=0 when it wasn't needed, even
 *         if the admin left the checkbox ticked)
 * @throws InvalidArgumentException
 */
function matrix_route_validate($srcId, $dstId, $allowCrossClass, $excludeRouteId = null) {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $srcId = (int) $srcId;
    $dstId = (int) $dstId;

    if ($srcId <= 0 || $dstId <= 0) {
        throw new InvalidArgumentException('Source and destination channels are required');
    }
    // matrix_core.py: "self-route (src == dst) is not allowed"
    if ($srcId === $dstId) {
        throw new InvalidArgumentException('A channel cannot be patched to itself');
    }

    $src = db_fetch_one(
        "SELECT id, label, regulatory_class, enabled FROM `{$prefix}comm_channels` WHERE id = ?",
        [$srcId]
    );
    $dst = db_fetch_one(
        "SELECT id, label, regulatory_class, enabled FROM `{$prefix}comm_channels` WHERE id = ?",
        [$dstId]
    );
    // matrix_core.py: "route references unknown channel"
    if (!$src || !$dst) {
        throw new InvalidArgumentException('Source or destination channel not found');
    }

    // matrix_core.py: "route {src}->{dst} exists" (exact directed pair only —
    // the reverse direction B->A is a separate, legitimate row for a
    // full-duplex patch, exactly as the DB's UNIQUE KEY (src,dst) allows).
    $dupSql  = "SELECT id FROM `{$prefix}comm_routes` WHERE src_channel_id = ? AND dst_channel_id = ?";
    $dupArgs = [$srcId, $dstId];
    if ($excludeRouteId !== null) {
        $dupSql   .= ' AND id != ?';
        $dupArgs[] = (int) $excludeRouteId;
    }
    if (db_fetch_value($dupSql, $dupArgs)) {
        throw new InvalidArgumentException('A patch from this source to this destination already exists');
    }

    $srcClass = $src['regulatory_class'] ?: 'internal';
    $dstClass = $dst['regulatory_class'] ?: 'internal';
    $blocked  = matrix_classes_blocked($srcClass, $dstClass);
    // matrix_core.py: "regulatory guard: ... blocked (needs override)"
    if ($blocked && !$allowCrossClass) {
        throw new InvalidArgumentException(
            'Regulatory guard: ' . $srcClass . ' <-> ' . $dstClass . ' patches are blocked by '
            . 'FCC Part 97.113 unless created with the cross-class override — which is audited '
            . 'and requires an explicit operator acknowledgment'
        );
    }

    return ['src' => $src, 'dst' => $dst, 'cross_class' => $blocked];
}

/** Clamp + validate gain_db into the sane console range; throws on out-of-range. */
function matrix_normalize_gain($raw) {
    $g = round((float) $raw, 1);
    if ($g < -60.0 || $g > 20.0) {
        throw new InvalidArgumentException('Gain must be between -60.0 and 20.0 dB');
    }
    return $g;
}

/**
 * Create a patch. $in accepts: src_channel_id, dst_channel_id, gain_db
 * (default 0.0), priority (default 0), ducking (default true),
 * enabled (default true), allow_cross_class (default false), note.
 *
 * @return int new route id
 * @throws InvalidArgumentException on any validation failure
 */
function matrix_route_create(array $in, $userId = null) {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $srcId  = (int) ($in['src_channel_id'] ?? 0);
    $dstId  = (int) ($in['dst_channel_id'] ?? 0);
    $allowCrossClass = !empty($in['allow_cross_class']);

    $check = matrix_route_validate($srcId, $dstId, $allowCrossClass);

    $gainDb   = array_key_exists('gain_db', $in) ? matrix_normalize_gain($in['gain_db']) : 0.0;
    $priority = array_key_exists('priority', $in) ? (int) $in['priority'] : 0;
    $ducking  = array_key_exists('ducking', $in) ? (empty($in['ducking']) ? 0 : 1) : 1;
    $enabled  = array_key_exists('enabled', $in) ? (empty($in['enabled']) ? 0 : 1) : 1;
    $note     = trim((string) ($in['note'] ?? ''));
    if ($note !== '') { $note = substr($note, 0, 255); }

    db_query(
        "INSERT INTO `{$prefix}comm_routes`
            (src_channel_id, dst_channel_id, gain_db, priority, ducking, enabled,
             allow_cross_class, note, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [$srcId, $dstId, $gainDb, $priority, $ducking, $enabled,
         $check['cross_class'] ? 1 : 0, ($note !== '' ? $note : null), $userId]
    );
    return (int) db_insert_id();
}

/**
 * Update an existing patch. $in may include any of: src_channel_id,
 * dst_channel_id, gain_db, priority, ducking, enabled, allow_cross_class,
 * note. Only keys present in $in are changed. Re-validates the (src,dst,
 * allow_cross_class) triple every time — an edit that turns a benign route
 * into a cross-class one without the override is rejected exactly like a
 * fresh create would be.
 *
 * @throws InvalidArgumentException
 */
function matrix_route_update($id, array $in) {
    $prefix   = $GLOBALS['db_prefix'] ?? '';
    $existing = matrix_route_get($id);
    if (!$existing) {
        throw new InvalidArgumentException('Route not found');
    }

    $srcId = array_key_exists('src_channel_id', $in) ? (int) $in['src_channel_id'] : (int) $existing['src_channel_id'];
    $dstId = array_key_exists('dst_channel_id', $in) ? (int) $in['dst_channel_id'] : (int) $existing['dst_channel_id'];
    $allowCrossClass = array_key_exists('allow_cross_class', $in)
        ? !empty($in['allow_cross_class'])
        : (bool) $existing['allow_cross_class'];

    $check = matrix_route_validate($srcId, $dstId, $allowCrossClass, (int) $id);

    $sets = ['src_channel_id = ?', 'dst_channel_id = ?', 'allow_cross_class = ?'];
    $args = [$srcId, $dstId, $check['cross_class'] ? 1 : 0];

    if (array_key_exists('gain_db', $in)) {
        $sets[] = 'gain_db = ?';
        $args[] = matrix_normalize_gain($in['gain_db']);
    }
    if (array_key_exists('priority', $in)) {
        $sets[] = 'priority = ?';
        $args[] = (int) $in['priority'];
    }
    if (array_key_exists('ducking', $in)) {
        $sets[] = 'ducking = ?';
        $args[] = empty($in['ducking']) ? 0 : 1;
    }
    if (array_key_exists('enabled', $in)) {
        $sets[] = 'enabled = ?';
        $args[] = empty($in['enabled']) ? 0 : 1;
    }
    if (array_key_exists('note', $in)) {
        $note = trim((string) $in['note']);
        $sets[] = 'note = ?';
        $args[] = ($note === '') ? null : substr($note, 0, 255);
    }

    $args[] = (int) $id;
    db_query("UPDATE `{$prefix}comm_routes` SET " . implode(', ', $sets) . ' WHERE id = ?', $args);
    return true;
}

/** Delete a patch by id. Returns false if it didn't exist (not an error). */
function matrix_route_delete($id) {
    $prefix   = $GLOBALS['db_prefix'] ?? '';
    $existing = matrix_route_get($id);
    if (!$existing) {
        return false;
    }
    db_query("DELETE FROM `{$prefix}comm_routes` WHERE id = ?", [(int) $id]);
    return true;
}

} // function_exists guard
