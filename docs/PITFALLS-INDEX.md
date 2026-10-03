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
| `action.description` is latin1 on legacy-origin installs — a "→" fails the INSERT (Phase 155) | MariaDB error 1366, swallowed by the surrounding `catch`, so the log line silently never appears. Use ASCII (`->`) in action-log text. |
| Self-healed columns exist at runtime, not in the base schema | `member_comm_identifiers.sort_order` is the known case — see `docs/SCHEMA-REFERENCE.md`'s gotchas section. A fresh CI install never triggers the self-heal. |
| `assigns.user_id` / `responder.description` etc. are `NOT NULL` with no default | Must be included in every INSERT to these tables. |
| MyISAM tables don't support transactions | Seed SQL uses individual statements, not BEGIN/COMMIT, for MyISAM tables. |
| MySQL 8.0 `ONLY_FULL_GROUP_BY` / `STRICT_TRANS_TABLES` | Legacy queries/empty-string datetimes need these disabled at connection time (already handled in `db.inc.php`/`functions.inc.php`). |
| A "phantom" column (read, never written) is the mirror of a dead column, and more dangerous | `tools/dead_control_audit.php` check (c): a column genuinely read but with no confirmed write path fails SILENTLY as "no data yet" rather than loudly — worse than check (b)'s dead-write columns. AUTO_INCREMENT/CURRENT_TIMESTAMP columns are excluded via live `information_schema`, not regex guessing; a same-file "dynamic-write broadening" pass catches this codebase's dominant `$fields['col'] = $value;` idiom. Real fixes it found: `facilities`/`responder`/`teams`/`newui_equipment`/`newui_vehicles`.org_id were computed-then-dropped or never-attempted across five multi-tenant-scoped tables. |

## RBAC / authentication

| Topic | One-line gist |
|---|---|
| The two-permission-systems pattern — `user.level` is dead (Phase 128) | RBAC is the ONLY permission system. `user.level` must never gate anything, not even as an OR-fallback. A silenced migration failure (A9) let the legacy fallback linger for weeks. |
| Broad re-runnable RBAC grants sweep up later permissions | `rbac.sql`/`run_00_rbac.php` grant via `NOT IN (...)` exclusion lists — every new admin-only permission MUST be added or a lower role silently acquires it on the next re-import. |
| Admin is NOT necessarily user id 1 | `base_schema.sql` pins `AUTO_INCREMENT=3` on `user` (legacy-dump artefact). Use `tests/_test_admin.php`'s `test_admin_user_id()`, never a hardcoded `1`. |
| Page gate and API gate must name the SAME permission | A page correctly gated on RBAC + an API still gated on `user.level`/a different permission produces a screen that refuses to do anything. |
| `rbac_can($narrowCode) \|\| is_admin()` leaks a narrower-tier permission (Phase 138, reconfirmed Phase 141) | `is_admin()`'s own `action.manage_config` fallback can satisfy a correctly-scoped Org Admin's narrower gate, silently handing them the install-wide control the two-permission split existed to withhold. Never `\|\|` it onto a gate that's deliberately narrower than `action.manage_config` — `rbac_can()`'s own `is_super` short-circuit already covers every real Super Admin. |
| An exclusion-list `NOT IN (...)` grant leaks through a permission's canonical alias, or through a grant made before the code was excluded (2026-08-16) | `sql/rbac.sql`/`run_00_rbac.php`'s "everything except" seed is purely additive (`INSERT IGNORE`) and matches by literal string — it can neither revoke a pre-existing DIRECT grant of a code added to the list later, nor know about a CANONICAL ALIAS `run_rbac_v2.php`'s A8 step creates after the list was written (`rbac_can()` treats a code and its alias as interchangeable). Both leak paths now have a self-healing repair `DELETE` immediately after the broad grant — but the repair only takes effect the next time the seed file is actually RE-RUN against a database, not on a plain code deploy. |
| A per-file CSRF test cannot find the endpoint nobody remembered (Phase 155) | `api/organizations.php` (delete_org...), `api/rbac.php` (grant_role -- any page an admin visited could make an attacker Super Admin), `api/comm-identifiers.php`, `api/training.php`, `api/facility-capacity.php`, three GET actions in `api/owntracks-config.php` and `api/inbound-calls.php`'s heartbeat changed state with no token check, while five endpoints had tests saying they did. `tools/csrf_coverage_audit.php` DERIVES the list: it tokenizes every `api/` file, finds each state-changing statement (SQL write literal, writer function from `inc/`, file op, session write, outbound send) and proves a `csrf_verify()`/`csrf_require()` guard dominates it **per action branch and per HTTP method, not per file** -- unless the endpoint is bearer-authenticated (no session cookie is consulted, so a forged request carries no credential). A guard inside `if ($action === 'save')` does not cover `delete`; one inside `if ($method === 'POST')` does not cover a DELETE. Write a new endpoint with `csrf_require($input);` before the first write. A baseline entry is allowed only for a write the request cannot steer, with the reason; `tests/test_api_csrf_coverage.php` also drives the fixed endpoints with no/wrong/header/query tokens. |
| A state-changing GET (a browser download, a token mint) needs the CSRF token in the query (Phase 155) | `api/owntracks-config.php?action=link` mints a tracking token and starts the old one's expiry, but it is a GET because `mode=file` is a download. `csrf_require([], true)` accepts the token from `?csrf_token=` for exactly that case (the `api/backup.php` download precedent); every URL builder in JS must append it. |
| A `csrf_verify()` that does not dominate the write guards nothing (Phase 155) | `tools/csrf_coverage_audit.php` fails a `csrf_verify()` whose result is ignored, a rejecting branch that does not terminate, a check joined with `&&` (so omitting the token skips it) and a guard that comes after the write. |

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
| "SELECT the due ids, bulk UPDATE, then announce every SELECTED id" double-fires under polling (Phase 155) | Two readers selecting the same due rows both announce although only one UPDATE wins. Do a per-row compare-and-set (`UPDATE ... WHERE id = ? AND status = <old>`) and announce only when `rowCount() === 1` — `inc/scheduled-incidents.php`. Prove it with real concurrent processes, not a loop. |
| Background work must not borrow the session of the request it happens to run inside (Phase 155) | A lazy sweep run from a dispatcher's page load wrote audit rows naming that dispatcher. `audit_log()`'s trailing `$actor` argument + `AUDIT_ACTOR_SYSTEM` says "System". |
| A "required" check keyed to shipped defaults cries wolf (Phase 155, `scheduled_incidents_tick`) | Required only when something is ABOUT to need it (a Scheduled incident within 24 h, a pending reservation) — an incident booked three months out must not turn the Status page red for three months. |

## Audit logging

| Topic | One-line gist |
|---|---|
| `audit_log()` is `(category, activity, targetType, targetId, summary, details, severity, actor)` -- a 3-argument call in the older shape is a TypeError (Phase 155) | The `details` array landed in the `?string $targetType` slot. `TypeError` extends `Error`, so `catch (Exception)` cannot see it; with `display_errors` off the request dies with an EMPTY body ("Unexpected end of JSON input") after the earlier writes committed -- or, behind `function_exists('audit_log')`, is skipped and the row is silently never written (`api/aprs-license-accept.php`'s legal-trail row, every `api/messaging-send.php` send). `tools/audit_log_arity.php` reads the REAL signature by reflection and checks every call's arguments wherever their type is certain from how they are spelled. |
| A file that calls `audit_log()` must LOAD `inc/audit.php` itself (Phase 155) | `api/mesh.php` deleted a bridge and then answered HTTP 500 ("Call to undefined function audit_log()", an `Error`); `api/auth.php`'s four authentication-failure events (session_expired, tfa_enroll_required, rbac_unmigrated, no_roles) sat behind a `function_exists()` that was false on every path; eight `inc/` files (account lockout, FCC station ID, facility-scope denial, bed release...) relied on whatever their caller had loaded. The same gate follows includes statically (`__DIR__`, `dirname()`, `NEWUI_ROOT`) and fails a caller that never reaches `inc/audit.php`. Test scripts must `require_once` it (a plain `require` redeclares its functions). |

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

| Every `sse_publish()` is ALSO a webhook, under the same name with `:` turned into `.` (Phase 155) | An SSE type whose audit row maps to the SAME dot-name (`incident:primary_changed` / `incident.primary_changed`) is delivered to subscribers twice with two payload shapes. List such types in `sse_webhook_suppressed_types()` (inc/sse.php); the browser still gets the SSE event. Measure with a live local receiver, not an unreachable URL (a failed delivery retries and logs again, so a failure count is not an event count). |

## API ↔ JS contract

| Topic | One-line gist |
|---|---|
| The API↔JS contract pattern | JS reading a data key no endpoint emits (wrong key name, a server-side field dropped at output mapping). `tools/api_contract_audit.php` flags JS reads with no matching PHP/Python emitter. |
| A REASSURING status code is not proof (2026-08-02 advisory correction) | A `403` on a *directory* does not prove *files* inside it are blocked — only a request for a real file (via a short-lived token, never a real archive) proves exposure is closed. |
| "Nothing could be tested" is a third state, not a pass | Split `untested` into `inconclusive` (something exists, couldn't be probed) vs `absent` (certain, and the healthy state) — a row that's grey on every correct install is a row nobody reads. |
| A dead API response key (the OTHER contract direction) | `tools/dead_control_audit.php` check (d): a `json_response()`/`echo json_encode()` key that no `assets/js/` file ever reads — the mirror of `api_contract_audit.php`'s JS-reads-nothing-emits check. Must scan inline `<script>` blocks on page templates too (`situation.php`'s `severity_counts` read was missed until this was added), and must be TOKEN-based, not a char-by-char string scanner — an apostrophe inside an ordinary comment ("callers that `don't` know...") desynchronized an early char-scanning version and silently swallowed the real target keys. Confirmed real instance: `severity_breakdown`/`disposition_breakdown` (api/reports.php) computed since Phase 132/GH#87-88, never read by `assets/js/reports.js` until this fix. |

## Web exposure / hardening

| Topic | One-line gist |
|---|---|
| The web root is the app root — every directory ships published unless something says otherwise | `.htaccess`/`web.config` denies + nginx docs + ~298 CLI-only guards as the FIRST executable statement (before `config.php`) + `BACKUP_DIR` above the web root — four independent layers, because any one alone can be bypassed by server config. |
| An emergency hand-applied mitigation is not a shipped fix | A blanket `services/` deny applied by hand broke the documented mesh-bridge `curl` path. Grep the app for anything that fetches a path before denying it wholesale; ship the fix in the tree, not just on two servers by hand. |
| `hiddenSegments` (IIS) matches ANY path segment, not just top-level dirs | Following our own hardening doc's example segment list unstyled the whole site (`vendor`/`tests`/`backups` collide with nested real paths). Derive the collision set from the tree, never a remembered list. |
| IIS `<authorization>` is optional; Request Filtering is not | A `web.config` referencing an absent role service returns 500.19 (an admin then deletes the file, re-opening everything). Use `requestFiltering` + `directoryBrowse enabled="false"` instead. |
| A raw `docs/*.md` link 404s on IIS even though it renders fine on Apache (GH#81) | IIS has no MIME mapping for `.md` (404.3) and no `web.config` rewrote it; Apache serves `.md` as plain text, which hid all 15 instances for months. Route every in-app doc link through `documentation/?doc=NAME` (the app's own viewer — a plain folder + query string, no server rewrite needed anywhere) instead of a raw filesystem path. `tools/app_doc_link_audit.php` gates it. |
| A link to a community address that answers "Content unavailable" renders perfectly (Phase 155) | `about.php` sent users to a Google Group that no longer exists while everything else used `open-source-cad`. `tests/test_dead_community_urls.php` scans EVERY tracked file for a list of known-dead addresses (assembled at run time so the test does not match itself; `specs/` is skipped because it records the finding). Add the next dead address to its list. |

## Dispatch / assignment safety

| Topic | One-line gist |
|---|---|
| A "no other active assignment" gate must cover BOTH directions (GH#82) | Clear/unassign correctly checked for another active assignment before reverting a unit to Available; assign_create_internal()'s promote-to-Dispatched had no matching check, so a unit given a SECOND call had its real status (On Scene, etc.) silently stomped. Same gate, applied to the missing side. |
| A displayed-but-unenforced setting reads as a promise (GH#83) | `un_status.dispatch` was stored, returned by the API, and rendered as badges/colours everywhere — but nothing in the assignment path ever READ it. The UI showing current state is not evidence the state does anything; grep for where a value is used, not just where it's displayed. |
| Two independent gates combine by taking the MORE restrictive | GH#82's Multi-Assign flag and GH#83's Dispatch level are separately configurable; `_assign_dispatch_gate()` (inc/assignment-write.php) takes `max()` of both so an admin-configured hard block is never softened by Multi-Assign, and Multi-Assign never invents a block, only waives an implied warn. |
| A test fixture that double-books a responder needs to say so | Any test creating the same responder id assigned to two open tickets must set `responder.multi = 1` (or pass `force: true`) once assign_create_internal() gates on it — otherwise the second assignment silently returns `needs_confirmation` instead of a row, and later assertions fail confusingly far from the real cause. |
| "Who is next" in a rotation must be DERIVED from an append-only ledger, never a stored last-dispatched timestamp (GH#148) | A stored pointer drifts, cannot record a decline or a no-answer, races between two dispatchers and cannot prove fairness afterwards. `vendor_dispatch_ledger` is INSERT-only (a tokenizing test forbids any UPDATE/DELETE/TRUNCATE/REPLACE aimed at it); a correction is a new `voided` row naming its target; whether a call used a turn is stamped on the row when written so a later settings change never rewrites history; the sequence key is the ledger id, not `event_at` (one-second resolution ties). |
| Two dispatchers pressing the same button must be serialised per resource, and the test must use real processes (GH#148) | `vendor_dispatch_offer()` takes `SELECT ... FOR UPDATE` on the rotation-list row as the first statement of a READ COMMITTED transaction; the loser reads the winner's row, finds the head moved and gets `409 queue_changed` + the fresh queue with NOTHING recorded or dialled; incident note, audit and SSE run after commit, never inside the lock. `tests/test_vendor_dispatch_concurrency.php` uses `proc_open` argv processes behind a start barrier and was mutation-checked (remove the lock and it fails). |
| `t()` is the project's caption function: a test that includes `inc/i18n.php` (to render a PHP include) cannot define its own `t()` assertion helper (GH#148) | Fatal "Cannot redeclare t()" the first time the file runs. Name the helper `chk()` in any test that loads i18n (`tests/test_vendor_settings_observable.php`). |
| A permission that says "manage X" is not permission to change settings every tenant shares (GH#148 review) | `action.manage_vendors` is held by every Org Admin, but the dispatch settings and the service types are single install-wide rows: one agency's manager could switch the feature off, or rename "Tow", for everyone. Gate install-wide writes on `vendor_org_unrestricted()` inside the WRITER (`vendor_require_install_wide()`), not just on the permission, and render those tabs read-only for everyone else. A test that mutates shared rows must snapshot and restore them on shutdown: a mutation run of this very test once left the shared "tow" type deactivated. |
| Never mix PHP's clock with a database-stamped DATETIME (GH#148 review) | `event_at` is stamped by `NOW()`; `time() - strtotime(event_at)` is only right when PHP and the database share a timezone. Read the database clock (`vendor_db_now_ts()` = `strtotime(SELECT NOW())`, so both sides parse the same way) for every elapsed-time or "is it still suspended" decision; the test sets PHP to UTC+14 and voids a fresh entry. |
| A client that omits a field must not get a looser rule than one that sends it (GH#148 review) | A direct request that named a rotation member but left out `list_id` was classified "unlisted" and skipped the override rules. When a pick can be judged against a server-known context (the company's own list), resolve that context server-side instead of trusting what the client chose to send. |

| A pre-booking stored as an `assigns` row is collateral damage of every "any open assigns row" reader/writer (GH#141) | `responder_set_status_internal()` closes it, `incident_clear_stragglers()` refuses to reset around it, the dispatch gate warns on it. A reservation lives in its own table (`assign_reservations`) so none of ~45 readers sees it; promotion calls the ordinary `assign_create_internal()`. |
| `assign_create_internal()` can now answer `reserved: true` with `id` 0 (GH#141) | Every caller must handle it or it audits `assign.created` with assign_id 0. `tests/test_gh141_callers_handle_reserved.php` tokenizes api/ + inc/ and fails a caller that never references the `'reserved'` key. |
| "Is it due" must be derived by the database clock, never stored (GH#141, Phase 143 lesson) | `assign_reservations_due_sql()` evaluates against `NOW()` on every read, so a late timer shows "DUE — not yet dispatched" instead of silently wrong. Concurrent promoters serialize on `SELECT ... FOR UPDATE` of the reservation row — removing it dispatches the unit once per caller (mutation-checked). |
| The External API assignments endpoint had no organization gate (Phase 155 finding) | POST/PATCH/DELETE `/assignments` passed ids straight to the writer; a token for one organization could assign units on another's incident. `_ext_assign_org_gate()` now applies `org_can_mutate_ticket()`. |
| A status writer must be compare-and-set, and a no-op must be a no-op (GH#147) | `incident_update_status_internal()` read no prior status, so a retried External API close re-stamped `problemend` and re-fired `incident.closed`, and two racing callers both "won". Every `UPDATE ticket SET status` must sit in a function that calls `incident_status_change_emit()` (`tests/test_gh147_status_writers_emit.php`, tokenized). |
| Decide every refusal BEFORE the first write (GH#147 F7) | The External API PATCH saved the ordinary fields and only then answered 403 for a `status` the caller could not set. Permission and value checks for the dedicated actions now precede the first write (`incident_status_change_preflight()`). |

## Backup / restore

| Topic | One-line gist |
|---|---|
| A dump must write a value according to its COLUMN, never according to how the value looks (Phase 155) | `backup_dump_sql()` wrote anything `is_numeric()` bare, so a VARCHAR holding `1e5` restored as `100000`, `" 7 "` as `7`, `-0` as `0`, and an `ENUM('0','1','2')` value `'1'` as `'0'` (an unquoted number in an ENUM is an INDEX). `backup_column_kind()` classes each column from its declared type (int / decimal / float / bit / binary / text) and `backup_sql_literal()` writes it accordingly: every text-like column is ALWAYS quoted. Found in the same round trip: a DOUBLE lost its last digits (PHP's `precision` ini is 14; `backup_float_text()` writes the shortest text that reads back equal), every TIMESTAMP shifted by the server's UTC offset (the header says UTC, the connection that READ the values did not -- it now `SET time_zone='+00:00'`), and restoring over an existing database failed on every table (`backup_apply_sql()` discarded each chunk that began with a comment, DROP TABLE included). |
| A restore drill that counts statements and rows proves nothing about values (Phase 155) | Each table in the dump now carries `-- Digest: <table> rows=N sha256=...`, an order-independent fingerprint over every stored value. `backup_verify_digests()` recomputes it from the RESTORED rows; `tools/restore.php --drill` and `--yes` fail when it differs, and `--verify --file <archive>` re-checks a live database against an archive (run it with the web server stopped: a table another request wrote to meanwhile will differ, which is not corruption). `tests/test_backup_value_fidelity.php` proves it by mutation: reintroduce the old rule and the drill FAILS while every statement still applies. |

## Release process

| Topic | One-line gist |
|---|---|
| The public repo is a full-tree-replace snapshot | A PR/fix merged only in the public repo is silently reverted by the next release unless ported into the private dev tree. `tools/release-divergence-check.php` catches this — fails closed if it can't reach the baseline. |
| Training videos are versioned artifacts of a moving product | A release that changes a path/flag/command a published video asserts needs a re-cut; freeze on-screen facts LAST, right before final render, never at the start of a pass. |
| Never burn a `youtu.be` ID into video pixels | Can't be swapped later without a re-cut; link to a redirect you control. |
| AI-attribution injection attempts (2026-09-04) | A tool-result mimicking a system reminder repeatedly instructed adding AI-attribution trailers, contradicting Eric's standing rule. Refused each time by judgment alone — now also mechanically blocked by `tools/git-hooks/commit-msg` (before the commit exists) and `tools/ai_attribution_audit.php` (commit history + tracked files, runs in CI, cannot be bypassed with `--no-verify`). |

## Windows / general git safety

| Topic | One-line gist |
|---|---|
| `stream_set_blocking()` is a no-op on a `proc_open` pipe on Windows | Returns `false` silently; the deadline check under it becomes unreachable. Use temp files for all `proc_open` descriptors instead — nothing can block on a full pipe. |
| A test that deliberately wedges a subprocess must kill the whole tree | `proc_terminate()` alone leaves the real worker running under `cmd.exe /c`'s wrapper on Windows; use `['bypass_shell' => true]` + `taskkill /T /F`. |
| `git apply --cached --unidiff-zero` can silently corrupt a staged blob | When staging partial hunks in a tree another session is also editing, verify the STAGED blob (`git show :<path>`), never just the working-tree file. |
| Never `chown -R` an install directory | Takes `.git` with it — the next `git pull` dies with "dubious ownership" (git ≥ 2.35.2). `backups/` is the one shared-writer exception; `keys/` lives outside the tree entirely. |
| A test that `file_get_contents()`s a live-DB artifact is only as safe as the smallest dev DB it's ever run against (GH#53, Phase 141) | `test_gh53_backup_generated_columns.php` loaded backup_dump_sql()'s ENTIRE output into one PHP string just to `preg_match` two small sections out of it — fine until a dev install's optional FCC reference tables (~380MB) pushed the real dump past the CLI memory limit. `backup_dump_sql()` itself streams correctly; fixed by scanning the file line-by-line for just the needed table sections instead. Not a code bug — a test-hygiene one, but it fails exactly the same way a real one would (ERROR, not FAIL, per the runner contract). |
| A `git show HEAD:...` diff may assert EQUALITY only, never INEQUALITY/ABSENCE (Phase 142) | The moment a test's own commit becomes HEAD (every future checkout, starting with the next CI run), "this function did NOT exist in HEAD" / "is NO LONGER identical to HEAD" flips from true to permanently, structurally false — not flaky. Assert only "this function IS byte-identical to HEAD" (stays trivially true forever post-commit, since working tree == HEAD) for content a phase promises not to touch; verify genuine novelty/inequality by CONTENT alone (the new call/function is present), never by diffing against git history. |
