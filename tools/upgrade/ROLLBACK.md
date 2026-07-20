# Upgrade Rollback Procedure

If the upgrade orchestrator fails partway through, the system is in a partially-migrated state. Follow this procedure to return to a known-good v3.44 state.

## Before you panic

The schema migrations are **add-only**. Even if step 4 (install_fresh) added columns, your legacy v3.44 codebase will still work — extra columns are harmless. The only mutations that change behavior are:

- Step 5 (settings translator) — renamed some legacy `email_*` keys to `smtp.*`. Legacy v3.44 may not find SMTP settings it expects.
- Step 6 (level → role migration) — inserted rows in `user_roles`. Legacy v3.44 doesn't read this table.

## A. If the orchestrator failed in step 1 (preflight)

No mutations occurred. Resolve whatever the preflight reported and run again. No rollback needed.

## B. If failure was in step 2 (backup)

No mutations occurred yet. Investigate the backup error. Common causes:
- `mysqldump` not in PATH → install MySQL client tools or rely on the PHP fallback.
- Backup target disk full → free space and retry.
- DB credentials wrong → check `config.php`.

## C. If failure was in steps 4–6 (schema mutated)

Restore from the snapshot the orchestrator just took:

```bash
mysql -h <host> -u <user> -p <db_name> < tools/upgrade/backups/<timestamp>.sql
```

The added columns will be removed by the restore (the SQL dump captures the pre-mutation table definitions). RBAC v2 schema and seeded permissions go away with it.

After restore, your legacy v3.44 install runs as before.

## D. If failure was in step 7 (smoke test)

The schema is fully migrated, but a synthetic test failed. Three sub-cases:

1. **`every user has at least one grant` failed** — some users have no roles. Run the legacy migration tool to fix:
   ```bash
   php tools/migrate_rbac.php
   ```
   Then re-run the orchestrator from step 7 onwards (or just rerun smoke + postcheck).

2. **`audit_log writes` failed** — the audit_log table may be missing or read-only. Inspect the log file. The schema migration should have created `newui_audit_log`. If it didn't, run:
   ```bash
   php sql/run_rbac_v2.php
   ```

3. **Other smoke failures** — read the log, pinpoint the failing assertion, and apply the suggested fix in the smoke output.

If you cannot resolve the smoke failures, restore the backup as in case C. The system is functional in the legacy state, but you've lost the migration progress.

## E. If failure was in step 8 (postcheck)

Postcheck is read-only. A failure here doesn't change the system state. Read the report, address any reported issues, rerun postcheck:

```bash
php tools/upgrade/postcheck.php
```

Common postcheck "failures":
- **Orphan users (X)** — see C above; run migrate_rbac.php.
- **Schema check shows MISSING** — re-run the runner that should have created it (the postcheck tells you which).
- **Row counts don't match baseline** — investigate what changed. Some legitimate causes: log table grew during migration, audit_log gained entries from the migration itself.

## F. After successful rollback

If you fully restored the database and want to try the upgrade again:

1. Check what triggered the previous failure. The log file at `tools/upgrade/upgrade-<ts>.log` has every step's output.
2. Address the root cause (PHP version, missing extension, custom code, disk space).
3. Re-run preflight: `php tools/upgrade/preflight.php`
4. When green, run the orchestrator again: `php tools/upgrade/run.php`

The orchestrator is idempotent on already-applied steps — it'll pick up where the previous run left off without re-mutating.

## G. Worst case — corrupted DB

If the SQL backup is also corrupted, restore from your **most recent independent backup** (your nightly mysqldump cron, your hosting provider's snapshot, etc.). NewUI assumes you have a separate backup strategy in addition to the one taken at upgrade time; the orchestrator's snapshot is a safety net, not your only safety net.

## Getting help

Attach `tools/upgrade/upgrade-<ts>.log` to a support request at:

- GitHub Issues: https://github.com/openises/tickets/issues
- Wiki: https://github.com/openises/tickets/wiki
