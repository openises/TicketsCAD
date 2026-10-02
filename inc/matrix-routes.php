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
 * Phase 152 prerequisite #5 — the TRANSITIVE cross-class guard.
 * matrix_classes_blocked() (above) only ever compares ONE route's two
 * endpoints. The net-control design review found a real gap that misses
 * entirely: patch amateur<->Zello (Zello is `internal` class — never
 * blocked against anything) and, separately, Zello<->pstn (also never
 * blocked — `internal` is exempt from every pairwise rule) and NEITHER
 * individual patch is refused, yet the live matrix now mixes an FCC-
 * regulated amateur channel with a phone line through the Zello hop —
 * exactly the FCC Part 97.113 violation the pairwise guard exists to
 * prevent, just laundered through a middleman.
 *
 * Models every currently-ENABLED route as an UNDIRECTED edge (a patch
 * A->B and its later full-duplex sibling B->A are the same connectivity
 * fact for this purpose — audio mixing doesn't care about the arrow), BFS
 * from the proposed route's own endpoints across that graph PLUS the
 * proposed edge itself, and checks whether the resulting connected
 * component contains BOTH an `amateur` channel and a `commercial`/`pstn`
 * channel anywhere in it — not just at the two ends of the new edge.
 *
 * @param int      $newSrcId
 * @param int      $newDstId
 * @param int|null $excludeRouteId when re-validating an UPDATE, that
 *                 route's own id — its PRE-edit edge must not count as
 *                 part of the "existing" graph, or editing a route could
 *                 never legitimately change which component it's in.
 * @return array{crosses:bool, amateur:array|null, conflict:array|null}
 *   amateur/conflict are {id,class,label} for the two specific channels
 *   that create the violation (for a clear error message) — null when
 *   crosses is false. A missing/invalid channel id resolves to
 *   crosses=false here — that's the PAIRWISE check's job to refuse
 *   (matrix_route_validate() calls this only after its own existence
 *   check has already passed).
 */
function matrix_route_would_cross_class($newSrcId, $newDstId, $excludeRouteId = null) {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $newSrcId = (int) $newSrcId;
    $newDstId = (int) $newDstId;

    $sql = "SELECT r.src_channel_id, r.dst_channel_id,
                   sc.regulatory_class AS src_class, sc.label AS src_label,
                   dc.regulatory_class AS dst_class, dc.label AS dst_label
              FROM `{$prefix}comm_routes` r
              JOIN `{$prefix}comm_channels` sc ON sc.id = r.src_channel_id
              JOIN `{$prefix}comm_channels` dc ON dc.id = r.dst_channel_id
             WHERE r.enabled = 1";
    $args = [];
    if ($excludeRouteId !== null) {
        $sql .= ' AND r.id != ?';
        $args[] = (int) $excludeRouteId;
    }
    $edges = db_fetch_all($sql, $args);

    $classOf = [];
    $labelOf = [];
    $adj = [];
    $addNode = function ($id, $class, $label) use (&$classOf, &$labelOf, &$adj) {
        $classOf[$id] = $class ?: 'internal';
        $labelOf[$id] = $label !== null && $label !== '' ? $label : ('#' . $id);
        if (!isset($adj[$id])) { $adj[$id] = []; }
    };
    $addEdge = function ($a, $b) use (&$adj) {
        $adj[$a][$b] = true;
        $adj[$b][$a] = true;
    };
    foreach ($edges as $e) {
        $a = (int) $e['src_channel_id'];
        $b = (int) $e['dst_channel_id'];
        $addNode($a, $e['src_class'], $e['src_label']);
        $addNode($b, $e['dst_class'], $e['dst_label']);
        $addEdge($a, $b);
    }

    $newSrc = db_fetch_one("SELECT id, regulatory_class, label FROM `{$prefix}comm_channels` WHERE id = ?", [$newSrcId]);
    $newDst = db_fetch_one("SELECT id, regulatory_class, label FROM `{$prefix}comm_channels` WHERE id = ?", [$newDstId]);
    if (!$newSrc || !$newDst) {
        return ['crosses' => false, 'amateur' => null, 'conflict' => null];
    }
    $addNode((int) $newSrc['id'], $newSrc['regulatory_class'], $newSrc['label']);
    $addNode((int) $newDst['id'], $newDst['regulatory_class'], $newDst['label']);
    $addEdge((int) $newSrc['id'], (int) $newDst['id']);

    // BFS the connected component reachable from the proposed edge.
    $visited = [(int) $newSrc['id'] => true];
    $queue = [(int) $newSrc['id']];
    while ($queue) {
        $cur = array_shift($queue);
        foreach (array_keys($adj[$cur] ?? []) as $nbr) {
            if (!isset($visited[$nbr])) {
                $visited[$nbr] = true;
                $queue[] = $nbr;
            }
        }
    }

    $amateur = null;
    $conflict = null;
    foreach (array_keys($visited) as $cid) {
        $cls = $classOf[$cid] ?? 'internal';
        if ($cls === 'amateur' && $amateur === null) {
            $amateur = ['id' => $cid, 'class' => $cls, 'label' => $labelOf[$cid]];
        }
        if (($cls === 'commercial' || $cls === 'pstn') && $conflict === null) {
            $conflict = ['id' => $cid, 'class' => $cls, 'label' => $labelOf[$cid]];
        }
    }

    if ($amateur !== null && $conflict !== null) {
        return ['crosses' => true, 'amateur' => $amateur, 'conflict' => $conflict];
    }
    return ['crosses' => false, 'amateur' => null, 'conflict' => null];
}

/**
 * Phase 152 prerequisite #5 — the periodic (not create/update-time) half
 * of the transitive guard: scans the WHOLE live comm_routes graph for a
 * connected component that mixes `amateur` with `commercial`/`pstn` and
 * has NO edge inside it carrying `allow_cross_class=1` anywhere — i.e. a
 * mixing nobody ever actually audited.
 *
 * Every create/update already re-validates via matrix_route_validate() ->
 * matrix_route_would_cross_class() on every single edit, so a route-level
 * mutation can never silently introduce this. The gap this closes is
 * different: a CHANNEL's own `regulatory_class` can be changed later
 * (api/channels.php, Phase 152 prerequisite #1) without touching
 * comm_routes at all — reclassifying a channel from `internal` to
 * `amateur` (or `commercial`/`pstn`) after routes already connected it to
 * others can retroactively turn a previously-safe topology into a live
 * cross-class path with nothing in the create/update path ever noticing.
 *
 * Read-time detection ONLY — mirrors this project's Phase 143 "sweep only
 * closes the audit record" convention, except here there is no record to
 * close, only an alert to raise. NEVER disables, deletes, or otherwise
 * mutates a route; that decision belongs to a human who can see WHY the
 * mixing happened (usually: undo the reclassification, or explicitly
 * acknowledge the mixing by editing one of the component's routes with
 * the override).
 *
 * @return array<int, array{channel_ids:int[], amateur:array, conflict:array}>
 *   one entry per violating connected component.
 */
function matrix_regulatory_scan_components() {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $edges = db_fetch_all(
        "SELECT r.src_channel_id, r.dst_channel_id, r.allow_cross_class,
                sc.regulatory_class AS src_class, sc.label AS src_label,
                dc.regulatory_class AS dst_class, dc.label AS dst_label
           FROM `{$prefix}comm_routes` r
           JOIN `{$prefix}comm_channels` sc ON sc.id = r.src_channel_id
           JOIN `{$prefix}comm_channels` dc ON dc.id = r.dst_channel_id
          WHERE r.enabled = 1"
    );

    $classOf = [];
    $labelOf = [];
    $adj = [];
    $edgeList = [];
    foreach ($edges as $e) {
        $a = (int) $e['src_channel_id'];
        $b = (int) $e['dst_channel_id'];
        $classOf[$a] = $e['src_class'] ?: 'internal';
        $labelOf[$a] = ($e['src_label'] !== null && $e['src_label'] !== '') ? $e['src_label'] : ('#' . $a);
        $classOf[$b] = $e['dst_class'] ?: 'internal';
        $labelOf[$b] = ($e['dst_label'] !== null && $e['dst_label'] !== '') ? $e['dst_label'] : ('#' . $b);
        $adj[$a][$b] = true;
        $adj[$b][$a] = true;
        $edgeList[] = [$a, $b, (bool) $e['allow_cross_class']];
    }

    // Connected components via BFS over every distinct channel touched by
    // an enabled route.
    $compOf = [];
    $components = [];
    foreach (array_keys($classOf) as $start) {
        if (isset($compOf[$start])) { continue; }
        $idx = count($components);
        $members = [$start];
        $compOf[$start] = $idx;
        $queue = [$start];
        while ($queue) {
            $cur = array_shift($queue);
            foreach (array_keys($adj[$cur] ?? []) as $nbr) {
                if (!isset($compOf[$nbr])) {
                    $compOf[$nbr] = $idx;
                    $members[] = $nbr;
                    $queue[] = $nbr;
                }
            }
        }
        $components[$idx] = $members;
    }

    $violations = [];
    foreach ($components as $idx => $members) {
        $amateur = null;
        $conflict = null;
        foreach ($members as $cid) {
            $cls = $classOf[$cid];
            if ($cls === 'amateur' && $amateur === null) {
                $amateur = ['id' => $cid, 'label' => $labelOf[$cid]];
            }
            if (($cls === 'commercial' || $cls === 'pstn') && $conflict === null) {
                $conflict = ['id' => $cid, 'class' => $cls, 'label' => $labelOf[$cid]];
            }
        }
        if ($amateur === null || $conflict === null) {
            continue;   // not a mixed component at all
        }
        $hasOverride = false;
        foreach ($edgeList as $edge) {
            if (($compOf[$edge[0]] ?? null) === $idx && $edge[2]) {
                $hasOverride = true;
                break;
            }
        }
        if (!$hasOverride) {
            $violations[] = ['channel_ids' => $members, 'amateur' => $amateur, 'conflict' => $conflict];
        }
    }
    return $violations;
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
        "SELECT id, label, adapter, regulatory_class, enabled FROM `{$prefix}comm_channels` WHERE id = ?",
        [$srcId]
    );
    $dst = db_fetch_one(
        "SELECT id, label, adapter, regulatory_class, enabled FROM `{$prefix}comm_channels` WHERE id = ?",
        [$dstId]
    );
    // matrix_core.py: "route references unknown channel"
    if (!$src || !$dst) {
        throw new InvalidArgumentException('Source or destination channel not found');
    }

    // Console rebuild (2026-09-07, veteran-dispatcher persona review) — the
    // dispatcher-to-dispatcher intercom is a leaf, never a mixing-bus
    // member: dispatcher chatter must be structurally impossible to route
    // onto ANY other channel, radio or otherwise (this is stricter than the
    // regulatory-class guard below, which allows internal<->anything —
    // internal-class Zello/SIP channels ARE legitimately patchable for
    // dispatch monitoring; the intercom specifically is not, by design, no
    // override exists for this one). No allow_cross_class escape hatch —
    // there is no "audited acknowledgment" that makes routing dispatcher-
    // only traffic onto the air acceptable.
    if ($src['adapter'] === 'intercom_dd' || $dst['adapter'] === 'intercom_dd') {
        throw new InvalidArgumentException(
            'The dispatcher intercom channel cannot be patched or coupled to any other channel — '
            . 'it is a dispatcher-only party line by design, never a mixing-bus member'
        );
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

    // Phase 152 prerequisite #5 — the pairwise check above only looks at
    // THIS route's two endpoints. A patch that's individually fine on
    // both sides (amateur<->internal, internal<->pstn) can still create a
    // live transitive path from an amateur channel to a commercial/pstn
    // one through an intermediate internal channel — see
    // matrix_route_would_cross_class()'s own docblock for the exact
    // net-control scenario this closes. Same audited-override escape
    // hatch as the pairwise check.
    $transitive = matrix_route_would_cross_class($srcId, $dstId, $excludeRouteId);
    if ($transitive['crosses'] && !$allowCrossClass) {
        throw new InvalidArgumentException(
            'Regulatory guard: this patch would create a live path between amateur channel "'
            . $transitive['amateur']['label'] . '" and ' . $transitive['conflict']['class']
            . ' channel "' . $transitive['conflict']['label'] . '" THROUGH one or more intermediate '
            . 'patches — blocked by FCC Part 97.113 unless created with the cross-class override, '
            . 'which is audited and requires an explicit operator acknowledgment'
        );
    }

    return ['src' => $src, 'dst' => $dst, 'cross_class' => $blocked || $transitive['crosses']];
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
 * Phase 152 prerequisite #6 — validate a proposed expires_at against the
 * mandatory-when-cross-class rule. Returns a normalized 'Y-m-d H:i:s'
 * string, or null when no expiry was requested (only legal for a
 * same-class route). Never returns a past timestamp.
 *
 * @throws InvalidArgumentException
 */
function matrix_route_validate_expiry($crossClass, $expiresAtRaw) {
    $trimmed = trim((string) ($expiresAtRaw ?? ''));
    if ($trimmed === '') {
        if ($crossClass) {
            throw new InvalidArgumentException(
                'An expiration time is required for a cross-class patch — FCC Part 97.113 '
                . 'overrides may never run unbounded. Same-class patches may be left without one.'
            );
        }
        return null;
    }
    $ts = strtotime($trimmed);
    if ($ts === false) {
        throw new InvalidArgumentException('expires_at is not a valid date/time');
    }
    if ($ts <= time()) {
        throw new InvalidArgumentException('expires_at must be in the future');
    }
    return date('Y-m-d H:i:s', $ts);
}

/**
 * Phase 152 prerequisite #6 — snapshot the acknowledging user's identity
 * + `user.callsign` (inc/dmr-station-id.php's own established source —
 * see api/dmr-station-id.php::_fcc_operator_callsign()) AT THIS MOMENT.
 * Deliberately a snapshot, not a live lookup at read time: a callsign on
 * file can change or be cleared later, and the audit record for an
 * already-acknowledged override must reflect what was actually true when
 * it was acknowledged.
 *
 * @return array{ack_by:int|null, callsign:string|null}
 */
function matrix_route_snapshot_operator($userId) {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $userId = (int) $userId;
    if ($userId <= 0) {
        return ['ack_by' => null, 'callsign' => null];
    }
    $cs = (string) db_fetch_value("SELECT `callsign` FROM `{$prefix}user` WHERE `id` = ?", [$userId]);
    return ['ack_by' => $userId, 'callsign' => ($cs !== '' ? substr($cs, 0, 16) : null)];
}

/**
 * Create a patch. $in accepts: src_channel_id, dst_channel_id, gain_db
 * (default 0.0), priority (default 0), ducking (default true),
 * enabled (default true), allow_cross_class (default false), note,
 * expires_at (Phase 152 prereq #6 — REQUIRED whenever the resolved
 * cross_class is true; optional and freely settable otherwise), group_id.
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
    $crossClass = $check['cross_class'];

    $expiresAt = matrix_route_validate_expiry($crossClass, $in['expires_at'] ?? null);
    $groupId = (array_key_exists('group_id', $in) && $in['group_id'] !== '' && $in['group_id'] !== null)
        ? (int) $in['group_id'] : null;

    $gainDb   = array_key_exists('gain_db', $in) ? matrix_normalize_gain($in['gain_db']) : 0.0;
    $priority = array_key_exists('priority', $in) ? (int) $in['priority'] : 0;
    $ducking  = array_key_exists('ducking', $in) ? (empty($in['ducking']) ? 0 : 1) : 1;
    $enabled  = array_key_exists('enabled', $in) ? (empty($in['enabled']) ? 0 : 1) : 1;
    $note     = trim((string) ($in['note'] ?? ''));
    if ($note !== '') { $note = substr($note, 0, 255); }

    // A route created WITH an expiry is acknowledged by the creating user
    // -- including a same-class one someone deliberately set to expire;
    // the audit trail should still say who, not just cross-class ones.
    $ackBy = null;
    $callsign = null;
    if ($expiresAt !== null) {
        $snap = matrix_route_snapshot_operator($userId);
        $ackBy = $snap['ack_by'];
        $callsign = $snap['callsign'];
    }

    db_query(
        "INSERT INTO `{$prefix}comm_routes`
            (src_channel_id, dst_channel_id, gain_db, priority, ducking, enabled,
             allow_cross_class, note, created_by, expires_at, group_id,
             operator_ack_by, operator_callsign)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [$srcId, $dstId, $gainDb, $priority, $ducking, $enabled,
         $crossClass ? 1 : 0, ($note !== '' ? $note : null), $userId,
         $expiresAt, $groupId, $ackBy, $callsign]
    );
    return (int) db_insert_id();
}

/**
 * Update an existing patch. $in may include any of: src_channel_id,
 * dst_channel_id, gain_db, priority, ducking, enabled, allow_cross_class,
 * note, expires_at, group_id. Only keys present in $in are changed.
 * Re-validates the (src,dst,allow_cross_class) triple every time — an
 * edit that turns a benign route into a cross-class one without the
 * override is rejected exactly like a fresh create would be.
 *
 * Phase 152 prerequisite #6 — RENEWAL semantics: supplying `expires_at`
 * in $in (any value, including '' to clear it on a route that's staying
 * same-class) is what makes this call a renewal — it re-snapshots
 * operator_ack_by/operator_callsign from $userId and clears warned_at so
 * the expiry-warning tick can fire again for the new deadline. Any OTHER
 * edit (gain, priority, a note) leaves the existing expiry/ack/callsign
 * completely untouched — an audited bridging route doesn't need to
 * re-litigate its own override on every unrelated field change (see
 * tests/test_phase152_transitive_cross_class.php's equivalent proof for
 * allow_cross_class persistence, which this mirrors).
 *
 * @throws InvalidArgumentException
 */
function matrix_route_update($id, array $in, $userId = null) {
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
    $crossClass = $check['cross_class'];

    $isRenewal = array_key_exists('expires_at', $in);
    if ($isRenewal) {
        $expiresAt = matrix_route_validate_expiry($crossClass, $in['expires_at']);
    } else {
        // Not touching expires_at on THIS call — but if the edit is what
        // newly makes this route cross-class (e.g. allow_cross_class
        // flipped true without also supplying a fresh expires_at), the
        // EXISTING value on file must already satisfy the requirement.
        $expiresAt = $existing['expires_at'];
        if ($crossClass && ($expiresAt === null || $expiresAt === '')) {
            throw new InvalidArgumentException(
                'An expiration time is required for a cross-class patch — FCC Part 97.113 '
                . 'overrides may never run unbounded. Provide expires_at to acknowledge this edit.'
            );
        }
    }

    $sets = ['src_channel_id = ?', 'dst_channel_id = ?', 'allow_cross_class = ?'];
    $args = [$srcId, $dstId, $crossClass ? 1 : 0];

    if ($isRenewal) {
        $snap = matrix_route_snapshot_operator($userId);
        $sets[] = 'expires_at = ?';         $args[] = $expiresAt;
        $sets[] = 'operator_ack_by = ?';    $args[] = $snap['ack_by'];
        $sets[] = 'operator_callsign = ?';  $args[] = $snap['callsign'];
        $sets[] = 'warned_at = NULL';   // a fresh expiry re-arms the warning tick
    }
    if (array_key_exists('group_id', $in)) {
        $sets[] = 'group_id = ?';
        $args[] = ($in['group_id'] === '' || $in['group_id'] === null) ? null : (int) $in['group_id'];
    }
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

/**
 * Phase 152 prerequisite #6 — one-click "renew" for the console's
 * countdown chip (console UI itself deferred to the Console rebuild task,
 * matching prerequisite #3's own console-mic.js/console-playback.js
 * deferral — this is the backend action it will call). Thin wrapper over
 * matrix_route_update() that always supplies expires_at, so every call
 * here is unconditionally a renewal by matrix_route_update()'s own
 * definition above.
 *
 * @param string $newExpiresAt any strtotime()-parseable future date/time
 * @throws InvalidArgumentException
 */
function matrix_route_renew($id, $newExpiresAt, $userId = null) {
    return matrix_route_update($id, ['expires_at' => $newExpiresAt], $userId);
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

/**
 * Console rebuild — group coupling (net-control persona review's core
 * ask: 3+ channels coupled as ONE named object with ONE shared timer,
 * never N independently-drifting pairwise patches). Expands to the FULL
 * DIRECTED MESH: every ORDERED pair among the given channels gets its own
 * `comm_routes` row (N channels -> N*(N-1) rows), all tagged with one
 * freshly-minted `group_id` and sharing one `expires_at`/
 * `operator_ack_by`/`operator_callsign` — matching this project's
 * standing decision (Phase 152 prerequisite #5) NOT to restructure
 * `matrix_core.py`'s proven single-hop mixing model; a coupling is a
 * presentation/control layer over ordinary pairwise routes, not a new
 * core concept.
 *
 * Every leg is validated through the EXACT SAME `matrix_route_validate()`
 * every single two-channel patch goes through (including the transitive
 * cross-class graph check and the intercom_dd leaf rule above) — coupling
 * is not a bypass of any existing safety rule, just a batch of ordinary
 * routes created together. Validation happens in a first pass, against
 * the CURRENT graph, BEFORE any row in this group is written — so a
 * group is all-or-nothing: if any single leg would be refused on its
 * own, NOTHING in the group is created, and the caller never sees a
 * half-formed mesh. (No DB transaction wraps the actual insert loop --
 * matching this file's own existing writers, none of which wrap a single
 * multi-statement write in one either; a process crash mid-insert is the
 * same class of pre-existing risk every other writer here already
 * accepts, not a new one this function introduces.)
 *
 * @param int[] $channelIds 2+ distinct comm_channels ids
 * @param bool $allowCrossClass
 * @param string|null $expiresAtRaw required if ANY leg in the resulting
 *        mesh resolves cross-class (matrix_route_validate_expiry() enforces
 *        this against the mesh as a whole, not leg-by-leg — one shared
 *        expiry for the whole group is the entire point)
 * @param int|null $userId
 * @param string|null $note applied to every leg
 * @return array{group_id:int, route_ids:int[]}
 * @throws InvalidArgumentException on any validation failure — nothing is created
 */
function matrix_group_create(array $channelIds, $allowCrossClass, $expiresAtRaw, $userId = null, $note = null) {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $ids = array_values(array_unique(array_map('intval', $channelIds)));
    if (count($ids) < 2) {
        throw new InvalidArgumentException('A group coupling needs at least 2 distinct channels');
    }
    foreach ($ids as $id) {
        if ($id <= 0) {
            throw new InvalidArgumentException('Invalid channel id in group');
        }
    }

    $pairs = [];
    foreach ($ids as $a) {
        foreach ($ids as $b) {
            if ($a === $b) { continue; }
            $pairs[] = [$a, $b];
        }
    }

    // Pass 1: validate every leg against the CURRENT (pre-group) graph.
    // Each leg's own resolved cross_class flag is captured now and reused
    // verbatim at insert time — never re-validated against a PARTIALLY
    // built group, which would make a leg's outcome depend on insert
    // order (an artifact of how this function happens to loop, not a real
    // fact about the finished mesh).
    $crossClassAny = false;
    $legCrossClass = [];
    foreach ($pairs as $i => $pair) {
        $check = matrix_route_validate($pair[0], $pair[1], $allowCrossClass);
        $legCrossClass[$i] = $check['cross_class'];
        if ($check['cross_class']) { $crossClassAny = true; }
    }

    $expiresAt = matrix_route_validate_expiry($crossClassAny, $expiresAtRaw);
    $ackBy = null;
    $callsign = null;
    if ($expiresAt !== null) {
        $snap = matrix_route_snapshot_operator($userId);
        $ackBy = $snap['ack_by'];
        $callsign = $snap['callsign'];
    }
    $cleanNote = ($note !== null) ? trim((string) $note) : '';
    $cleanNote = ($cleanNote !== '') ? substr($cleanNote, 0, 255) : null;

    $groupId = (int) db_fetch_value("SELECT COALESCE(MAX(group_id), 0) + 1 FROM `{$prefix}comm_routes`");

    // Pass 2: insert every leg using its pre-computed (pass-1) cross_class flag.
    $routeIds = [];
    foreach ($pairs as $i => $pair) {
        db_query(
            "INSERT INTO `{$prefix}comm_routes`
                (src_channel_id, dst_channel_id, gain_db, priority, ducking, enabled,
                 allow_cross_class, note, created_by, expires_at, group_id,
                 operator_ack_by, operator_callsign)
             VALUES (?, ?, 0, 0, 1, 1, ?, ?, ?, ?, ?, ?, ?)",
            [$pair[0], $pair[1], $legCrossClass[$i] ? 1 : 0, $cleanNote,
             $userId, $expiresAt, $groupId, $ackBy, $callsign]
        );
        $routeIds[] = (int) db_insert_id();
    }

    return ['group_id' => $groupId, 'route_ids' => $routeIds];
}

/**
 * Tear down every route in a group. Deliberately unconditional and
 * immediate — no confirmation, no partial break — matching the veteran-
 * dispatcher review's explicit design lesson: creating a patch is
 * deliberate (multi-step, confirmed), but breaking one must be instant,
 * because a console always fails toward LESS connectivity, never more.
 *
 * @return int number of routes removed (0 if the group id didn't exist —
 *         not an error, matching matrix_route_delete()'s own idempotent
 *         "not found is not an error" convention)
 */
function matrix_group_break($groupId) {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $groupId = (int) $groupId;
    if ($groupId <= 0) {
        return 0;
    }
    $existingIds = db_fetch_all("SELECT id FROM `{$prefix}comm_routes` WHERE group_id = ?", [$groupId]);
    if (empty($existingIds)) {
        return 0;
    }
    db_query("DELETE FROM `{$prefix}comm_routes` WHERE group_id = ?", [$groupId]);
    return count($existingIds);
}

/** All routes in a group, joined with channel display fields — for the patch rail. */
function matrix_group_routes($groupId) {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    return db_fetch_all(
        "SELECT r.*,
                sc.channel_key AS src_key, sc.label AS src_label,
                dc.channel_key AS dst_key, dc.label AS dst_label
           FROM `{$prefix}comm_routes` r
      LEFT JOIN `{$prefix}comm_channels` sc ON sc.id = r.src_channel_id
      LEFT JOIN `{$prefix}comm_channels` dc ON dc.id = r.dst_channel_id
          WHERE r.group_id = ?
       ORDER BY r.id",
        [(int) $groupId]
    );
}

/**
 * Console rebuild (2026-09-07) — validate the ONE real `comm_channels` side
 * of a browser-leg route (a dispatcher's own mic/speaker <-> a matrix-
 * backed channel like DMR). Deliberately NOT matrix_route_validate(): that
 * function requires BOTH sides to be real `comm_channels` rows, but a
 * `browser:<console_sessions.id>` channel is created dynamically in the
 * Python matrix's live process the instant a WebSocket connects
 * (services/audio-matrix/legs/browser.py) and is NEVER inserted into
 * `comm_channels` — see that file's BrowserLeg docblock and MatrixCore.
 * remove_channel()'s own comment ("every DB-loaded channel is static... a
 * browser-leg console session comes and goes with every tab open/close").
 * Routes touching a browser-leg channel are therefore EPHEMERAL: applied
 * directly to the live matrix via matrix_control_request() (never written
 * to `comm_routes`) and cleaned up automatically by the Python side when
 * the WebSocket closes — no synthetic `comm_channels` row, no stale-row
 * reaper needed.
 *
 * The full pairwise + transitive regulatory checks matrix_route_validate()
 * runs do not apply here: the browser leg's own regulatory_class is
 * 'internal' (matching intercom_dd's own choice — it's the dispatcher's
 * own voice, not an RF/PSTN circuit), and 'internal' is never a member of
 * any blocked pair (matrix_blocked_class_pairs()'s own docblock: "internal
 * <-> anything is always allowed"). The transitive-graph check exists to
 * catch an AUTOMATIC relay with no human decision in between two channels;
 * a dispatcher's own mic is by construction a human control point (they
 * decide, moment to moment, what to say and to whom), the same reasoning
 * that makes a human relaying between a radio and a phone line normal,
 * legal dispatch operation rather than the automatic bridge the FCC guard
 * targets. What DOES still apply, unconditionally: the channel must exist,
 * and the intercom_dd leaf rule (a dispatcher's mic must never be routable
 * to the fixed dispatcher-only party line through this generic path — its
 * own future multi-party wiring is a separate, presence-driven mechanism,
 * not this one).
 *
 * @return array the validated comm_channels row
 * @throws InvalidArgumentException
 */
function matrix_browser_leg_validate_channel($channelId) {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $channelId = (int) $channelId;
    if ($channelId <= 0) {
        throw new InvalidArgumentException('A channel id is required');
    }
    $ch = db_fetch_one(
        "SELECT id, channel_key, label, adapter, regulatory_class, enabled FROM `{$prefix}comm_channels` WHERE id = ?",
        [$channelId]
    );
    if (!$ch) {
        throw new InvalidArgumentException('Channel not found');
    }
    if ($ch['adapter'] === 'intercom_dd') {
        throw new InvalidArgumentException(
            'The dispatcher intercom channel cannot be reached this way — '
            . 'it is a dispatcher-only party line by design, never a mixing-bus member'
        );
    }
    return $ch;
}

} // function_exists guard
