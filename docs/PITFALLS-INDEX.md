# Pitfalls quick-reference index

A compact, scannable companion to the project's CLAUDE.md "Common Pitfalls" section
(that file lives outside this git repo — `TicketsCADFixes/CLAUDE.md`, one directory
above `newui-dev/`, not shipped publicly — so this index is hand-maintained here,
inside the repo, where every contributor and CI can read it). This does NOT replace
the narrative entries there: each row below is a one-line pointer, not the full
story. If a row looks relevant, go read the matching CLAUDE.md entry (search for the
**bold lead-in** text) before acting — the nuance that made each of these worth
recording usually lives in the paragraph, not the summary.

**Why this exists:** a scoped agent working only on, say, scheduled jobs shouldn't
have to read all ~70 entries — most are about a different subsystem entirely. Filter
by the group headers below, or `Grep` this file for a keyword, before reading the
full CLAUDE.md section.

Maintenance: add a row here in the same change that adds a new CLAUDE.md pitfall
entry. A row here with no matching CLAUDE.md entry (or vice versa) is stale — fix
whichever one drifted.

---

## Schema / data integrity

| Topic | One-line gist |
|---|---|
| Column existence varies by install age | Use try/catch with fallback queries; never assume a column exists. |
| Legacy `NOT NULL` columns with no default | Self-healing INSERT pattern: catch the "no default value" error, ALTER a type-appropriate default, retry. |
| `settings` vs `config` — two stores | `settings` (name/value) is what the UI writes + `get_variable()` reads. `config` (key/value) is a separate bootstrap store `get_setting()` reads — writing to one and reading the other silently returns the default forever (GH #79). |
| The schema-mismatch pattern (10+ instances) | SQL written against a REMEMBERED schema, silenced by a bare `catch {}` into a feature that quietly no-ops. Always `SHOW COLUMNS` before writing a query; never a silent catch; run `tools/schema_audit.php` before committing SQL. |
| The audit had a blind spot (Phase 125) | `schema_audit.php` looked at each string literal in isolation, missing SQL built via concatenation (`"INSERT INTO " . db_table('x') . " (...)"`). Fixed by `tools/sql_extract.php` stitching concatenation chains. |
| A migration tracker proves the script RAN, not that the schema still EXISTS | Drop a table during crash recovery and a tracker-based "already applied" check still says yes. `sql/schema_manifest.json` + `inc/schema-verify.php` check the LIVE schema instead. |
| UNIQUE key ending in a NULLable column constrains nothing for NULL rows (Phase 129) | MySQL/MariaDB treat every NULL as distinct in a unique index. Verify uniqueness by attempting a real duplicate INSERT, never by reading the DDL. |
| A NULLable LIFECYCLE column collapses the same way a NULLable discriminant does (Phase 143 `live_key`) | Generalizes `org_type_routing.match_key`'s NULL-collapse technique one step further: from "collapse a NULLable discriminant" to "collapse a NULLable lifecycle column" (`org_relationships_activations.deactivated_at`). A generated column can't reference its own table's `AUTO_INCREMENT` column (MariaDB SQLSTATE[HY000] 1901) — use plain `NULL` for the "never collides" case instead of a fabricated per-row string; verified live, not from the DDL, exactly like the original technique. |
| Self-healed columns exist at runtime, not in the base schema | `member_comm_identifiers.sort_order` is the known case — see `docs/SCHEMA-REFERENCE.md`'s gotchas section. A fresh CI install never triggers the self-heal. |
| `assigns.user_id` / `responder.description` etc. are `NOT NULL` with no default | Must be included in every INSERT to these tables. |
| MyISAM tables don't support transactions | Seed SQL uses individual statements, not BEGIN/COMMIT, for MyISAM tables. |
| MySQL 8.0 `ONLY_FULL_GROUP_BY` / `STRICT_TRANS_TABLES` | Legacy queries/empty-string datetimes need these disabled at connection time (already handled in `db.inc.php`/`functions.inc.php`). |

## RBAC / authentication

| Topic | One-line gist |
|---|---|
| The two-permission-systems pattern — `user.level` is dead (Phase 128) | RBAC is the ONLY permission system. `user.level` must never gate anything, not even as an OR-fallback. A silenced migration failure (A9) let the legacy fallback linger for weeks. |
| Broad re-runnable RBAC grants sweep up later permissions | `rbac.sql`/`run_00_rbac.php` grant via `NOT IN (...)` exclusion lists — every new admin-only permission MUST be added or a lower role silently acquires it on the next re-import. |
| Admin is NOT necessarily user id 1 | `base_schema.sql` pins `AUTO_INCREMENT=3` on `user` (legacy-dump artefact). Use `tests/_test_admin.php`'s `test_admin_user_id()`, never a hardcoded `1`. |
| Page gate and API gate must name the SAME permission | A page correctly gated on RBAC + an API still gated on `user.level`/a different permission produces a screen that refuses to do anything. |
| `rbac_can($narrowCode) \|\| is_admin()` leaks a narrower-tier permission (Phase 138, reconfirmed Phase 141) | `is_admin()`'s own `action.manage_config` fallback can satisfy a correctly-scoped Org Admin's narrower gate, silently handing them the install-wide control the two-permission split existed to withhold. Never `\|\|` it onto a gate that's deliberately narrower than `action.manage_config` — `rbac_can()`'s own `is_super` short-circuit already covers every real Super Admin. |
| An exclusion-list `NOT IN (...)` grant leaks through a permission's canonical alias, or through a grant made before the code was excluded (2026-08-16) | `sql/rbac.sql`/`run_00_rbac.php`'s "everything except" seed is purely additive (`INSERT IGNORE`) and matches by literal string — it can neither revoke a pre-existing DIRECT grant of a code added to the list later, nor know about a CANONICAL ALIAS `run_rbac_v2.php`'s A8 step creates after the list was written (`rbac_can()` treats a code and its alias as interchangeable). Both leak paths now have a self-healing repair `DELETE` immediately after the broad grant — but the repair only takes effect the next time the seed file is actually RE-RUN against a database, not on a plain code deploy. |

## Scheduled jobs / migrations

| Topic | One-line gist |
|---|---|
| A migration step that catches its own exception and exits 0 never ran | Add a companion VERIFY step that re-asks the database afterward and throws on mismatch. |
| Migration scripts must exit non-zero on failure | Detected by child exit code first; a bare string regex is fallback-only, and must NOT match legitimate success text containing "failed 0". |
| `/etc/cron.d` on a host with no cron daemon fails completely silently | `systemctl is-active cron` first. Prefer systemd timers; `sched_job_record()`/`sched_job_required()` give a real heartbeat + "shipped default is not usage" required-check. |
| A disabled feature must stop ACTING, not freeze its housekeeping | Stale-work cleanup (e.g. expiring old PAR cycles) must still run even when the feature itself is off, or re-enabling resumes a month-old alarm storm. |
| The test runner scored an exit-0 file with no summary as a PASS (Phase 129) | `tools/suite_contract.php`'s `test_all_tail()` truncates a failing file's output to its last ~50 lines — the real FAIL lines can be in the omitted head. Every test file needs the canonical `=== N passed, M failed ===` summary AND a non-zero exit on failure. |
| `INSTALL IGNORE`/dedup on a NULLable-column UNIQUE key enforces nothing | Same NULL-in-unique-index trap as above, seen again in RBAC grant seeding (Phase 129). |
| NEVER put a semicolon inside a string value in a `.sql` file a `run_*.php` importer splits on `;` | Silently truncates the rest of the file — zero rows seeded, exit 0. |

## Routing / broker / messaging

| Topic | One-line gist |
|---|---|
| `_is_routed_forward` / `_route_depth` / `_routed` are trust flags only the router may set | A caller-controlled value here is a forgery surface for bypassing routing rules — only honor them when `_is_routed_forward` is genuinely set by `router_forward()`. |
| `broker_send()` callers must `require inc/sse.php` first | `local_chat`'s send path calls `sse_publish()` — without the include, sends fatal with "undefined function". |
| `chat_messages.from` is a LEGACY column | Only migrated installs have it; an unconditional INSERT naming it breaks fresh installs. Check `information_schema` first. |
| `dmr_channels.last_seen_at` is NOT a heartbeat | Stamped on RX ingest only — a quiet talkgroup looks "dead" in minutes. Use the bridge's `/health` endpoint for liveness. |
| Channel registry sync never clobbers hand-set overrides | label/color/sort_order/enabled are set on CREATE only for managed rows. |
| A message dedupe key lives in `broker_receive()`, not per-adapter (Phase 134) | Every poll-based channel gets the same at-least-once-safe guarantee automatically by declaring `dedupe_key`, rather than each adapter reinventing it. |
| A real, unbounded `INSERT IGNORE` dedupe check still needs asking the database | Never trust reading the DDL — insert the same pair twice through the real table and assert the second is silently ignored. |
| A setting can have a full write path, UI, and migration and STILL have no consumer (`tile_mode`) | The acceptance test for a new setting must assert an *observable output changes*, not just that the value round-trips through the DB. |

## Real-time (SSE)

| Topic | One-line gist |
|---|---|
| Authorize at publish time, not read time, when a per-subscriber fact changes faster than a connection's lifetime (Phase 142) | A connection's own visibility snapshot (org membership, RBAC entitlement) is stable and safe to compute once at connection-open; a volatile per-resource fact (does an active cross-org share still exist for THIS ticket) must be re-resolved fresh by the WRITER on every publish instead — otherwise a revoked grant only stops mattering after `$maxRuntime` (up to 5 minutes), not the next event. `_sse_share_orgs_for_ticket()` re-queries live on every `sse_publish_for_incident()` call; the reader-side `$userOrgIds` snapshot never needs to shrink for the leak to stop, because the server just stops sending. |

## API ↔ JS contract

| Topic | One-line gist |
|---|---|
| The API↔JS contract pattern | JS reading a data key no endpoint emits (wrong key name, a server-side field dropped at output mapping). `tools/api_contract_audit.php` flags JS reads with no matching PHP/Python emitter. |
| A REASSURING status code is not proof (2026-08-02 advisory correction) | A `403` on a *directory* does not prove *files* inside it are blocked — only a request for a real file (via a short-lived token, never a real archive) proves exposure is closed. |
| "Nothing could be tested" is a third state, not a pass | Split `untested` into `inconclusive` (something exists, couldn't be probed) vs `absent` (certain, and the healthy state) — a row that's grey on every correct install is a row nobody reads. |

## Web exposure / hardening

| Topic | One-line gist |
|---|---|
| The web root is the app root — every directory ships published unless something says otherwise | `.htaccess`/`web.config` denies + nginx docs + ~298 CLI-only guards as the FIRST executable statement (before `config.php`) + `BACKUP_DIR` above the web root — four independent layers, because any one alone can be bypassed by server config. |
| An emergency hand-applied mitigation is not a shipped fix | A blanket `services/` deny applied by hand broke the documented mesh-bridge `curl` path. Grep the app for anything that fetches a path before denying it wholesale; ship the fix in the tree, not just on two servers by hand. |
| `hiddenSegments` (IIS) matches ANY path segment, not just top-level dirs | Following our own hardening doc's example segment list unstyled the whole site (`vendor`/`tests`/`backups` collide with nested real paths). Derive the collision set from the tree, never a remembered list. |
| IIS `<authorization>` is optional; Request Filtering is not | A `web.config` referencing an absent role service returns 500.19 (an admin then deletes the file, re-opening everything). Use `requestFiltering` + `directoryBrowse enabled="false"` instead. |
| A raw `docs/*.md` link 404s on IIS even though it renders fine on Apache (GH#81) | IIS has no MIME mapping for `.md` (404.3) and no `web.config` rewrote it; Apache serves `.md` as plain text, which hid all 15 instances for months. Route every in-app doc link through `documentation/?doc=NAME` (the app's own viewer — a plain folder + query string, no server rewrite needed anywhere) instead of a raw filesystem path. `tools/app_doc_link_audit.php` gates it. |

## Dispatch / assignment safety

| Topic | One-line gist |
|---|---|
| A "no other active assignment" gate must cover BOTH directions (GH#82) | Clear/unassign correctly checked for another active assignment before reverting a unit to Available; assign_create_internal()'s promote-to-Dispatched had no matching check, so a unit given a SECOND call had its real status (On Scene, etc.) silently stomped. Same gate, applied to the missing side. |
| A displayed-but-unenforced setting reads as a promise (GH#83) | `un_status.dispatch` was stored, returned by the API, and rendered as badges/colours everywhere — but nothing in the assignment path ever READ it. The UI showing current state is not evidence the state does anything; grep for where a value is used, not just where it's displayed. |
| Two independent gates combine by taking the MORE restrictive | GH#82's Multi-Assign flag and GH#83's Dispatch level are separately configurable; `_assign_dispatch_gate()` (inc/assignment-write.php) takes `max()` of both so an admin-configured hard block is never softened by Multi-Assign, and Multi-Assign never invents a block, only waives an implied warn. |
| A test fixture that double-books a responder needs to say so | Any test creating the same responder id assigned to two open tickets must set `responder.multi = 1` (or pass `force: true`) once assign_create_internal() gates on it — otherwise the second assignment silently returns `needs_confirmation` instead of a row, and later assertions fail confusingly far from the real cause. |

## Release process

| Topic | One-line gist |
|---|---|
| The public repo is a full-tree-replace snapshot | A PR/fix merged only in the public repo is silently reverted by the next release unless ported into the private dev tree. `tools/release-divergence-check.php` catches this — fails closed if it can't reach the baseline. |
| Training videos are versioned artifacts of a moving product | A release that changes a path/flag/command a published video asserts needs a re-cut; freeze on-screen facts LAST, right before final render, never at the start of a pass. |
| Never burn a `youtu.be` ID into video pixels | Can't be swapped later without a re-cut; link to a redirect you control. |

## Windows / general git safety

| Topic | One-line gist |
|---|---|
| `stream_set_blocking()` is a no-op on a `proc_open` pipe on Windows | Returns `false` silently; the deadline check under it becomes unreachable. Use temp files for all `proc_open` descriptors instead — nothing can block on a full pipe. |
| A test that deliberately wedges a subprocess must kill the whole tree | `proc_terminate()` alone leaves the real worker running under `cmd.exe /c`'s wrapper on Windows; use `['bypass_shell' => true]` + `taskkill /T /F`. |
| `git apply --cached --unidiff-zero` can silently corrupt a staged blob | When staging partial hunks in a tree another session is also editing, verify the STAGED blob (`git show :<path>`), never just the working-tree file. |
| Never `chown -R` an install directory | Takes `.git` with it — the next `git pull` dies with "dubious ownership" (git ≥ 2.35.2). `backups/` is the one shared-writer exception; `keys/` lives outside the tree entirely. |
| A test that `file_get_contents()`s a live-DB artifact is only as safe as the smallest dev DB it's ever run against (GH#53, Phase 141) | `test_gh53_backup_generated_columns.php` loaded backup_dump_sql()'s ENTIRE output into one PHP string just to `preg_match` two small sections out of it — fine until a dev install's optional FCC reference tables (~380MB) pushed the real dump past the CLI memory limit. `backup_dump_sql()` itself streams correctly; fixed by scanning the file line-by-line for just the needed table sections instead. Not a code bug — a test-hygiene one, but it fails exactly the same way a real one would (ERROR, not FAIL, per the runner contract). |
| A `git show HEAD:...` diff may assert EQUALITY only, never INEQUALITY/ABSENCE (Phase 142) | The moment a test's own commit becomes HEAD (every future checkout, starting with the next CI run), "this function did NOT exist in HEAD" / "is NO LONGER identical to HEAD" flips from true to permanently, structurally false — not flaky. Assert only "this function IS byte-identical to HEAD" (stays trivially true forever post-commit, since working tree == HEAD) for content a phase promises not to touch; verify genuine novelty/inequality by CONTENT alone (the new call/function is present), never by diffing against git history. |
