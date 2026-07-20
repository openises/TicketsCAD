# Upgrading from TicketsCAD v3.44 to NewUI v4

This guide walks you through upgrading a legacy v3.44 installation to NewUI v4. The process is reversible at every step until you cut over the live webroot. Plan on **15 minutes of dispatch downtime** for a small org (a few hundred members, a few thousand tickets); larger orgs scale linearly.

If you are running NewUI v4 already, you don't need this guide — you're done. If you're running v3.44 today, read on.

## Before you start

Three things you need:

1. **A backup of your current install.** The upgrade tooling takes its own snapshot, but always have an independent backup as well. A `mysqldump` plus a tarball of `tickets/` is enough.
2. **PHP 8.0 or newer.** PHP 8.2+ recommended. The preflight checker in step 1 below tells you if your version is OK.
3. **Console access to the server.** You'll run a handful of `php tools/...` commands. There is no web-based upgrade wizard yet.

The upgrade does **not** require:
- Internet access from the server (everything runs locally)
- Root / Administrator privileges (only DB write + filesystem write to the install dir)
- A maintenance window longer than ~15 minutes for typical sizes

## High-level overview

You'll end up running this in order:

```
1. php tools/upgrade/preflight.php          → green/yellow/red signal
2. php tools/upgrade/postcheck.php --snapshot pre.json
                                            → record current state
3. php tools/upgrade/run.php                → THE upgrade
4. php tools/upgrade/postcheck.php --compare pre.json
                                            → verify counts match
5. (Optional) cut over the live webroot     → see "Cut-over" below
```

Steps 1, 2, and 4 are read-only. Step 3 is the only step that changes data.

## Step 0: Get NewUI v4 source

Clone or download NewUI v4 to a sibling directory of your existing legacy install. Example layout:

```
/var/www/
  tickets/        ← your existing v3.44 install
  newui/          ← NewUI v4 (you'll install here)
```

Configure NewUI v4 to point at the **same database** as your legacy install. This is the key — both code lines use the same DB. NewUI extends the schema; it doesn't replace it.

Edit `newui/config.php` so its DB credentials match `tickets/config.php`.

## Step 1: Preflight check

```
cd /var/www/newui
php tools/upgrade/preflight.php
```

You'll see a table like:

```
=== TicketsCAD upgrade preflight ===

  [✓] PHP version                  8.2.4
  [✓] PHP extensions               all required loaded
  [✓] Database connection          MariaDB/MySQL 10.11.5
  [✓] DB engine version            10.11.5
  [✓] Legacy tables present        10 tables found
  [✓] Data volume                  120 members, 4321 tickets, 18 users
       → Estimated migration time: ~50 seconds
  [✓] Disk free for backup         free 12345.6MB
  [✓] RBAC v2 schema state         will be applied during upgrade
  [✓] Timezone alignment           PHP=America/New_York, DB=UTC
       → Mismatch is OK — RBAC stores via FROM_UNIXTIME

OVERALL: PASS — safe to proceed with the upgrade.
```

If anything reports **FAIL**, stop and resolve it before continuing. Common reasons:
- PHP version too old (< 8.0)
- Missing PHP extensions (`pdo_mysql`, `openssl`, `mbstring`, `json`, `zip`)
- Disk too full for backups
- A required legacy table is missing — likely a damaged install

The preflight does not change anything. It only reads.

## Step 2: Take a baseline snapshot

```
php tools/upgrade/postcheck.php --snapshot pre.json
```

This writes a small JSON file with row counts before the upgrade. Step 4 compares against it.

You can put `pre.json` anywhere — keep it near the upgrade log so you can match them up later.

## Step 3: Run the upgrade

```
php tools/upgrade/run.php
```

You'll see step-by-step output:

```
=== TicketsCAD upgrade orchestrator ===
Log file: /var/www/newui/tools/upgrade/upgrade-20260506-094500.log

[09:45:00] Step 1/8 — preflight
  ... (preflight rerun, must still pass)
  done in 540ms
[09:45:01] Step 2/8 — database backup
  backup -> /var/www/newui/tools/upgrade/backups/20260506-094501.sql (12,345,678 bytes)
  done in 8200ms

About to APPLY schema migrations to the live database.
Press Enter to continue, Ctrl+C to abort.
```

**The "press Enter to continue" prompt is your point of no return for step 3.** Up to here, nothing has been written. Press Enter to proceed; Ctrl+C to abort cleanly. The backup at this point is your insurance — keep it.

The orchestrator continues:

```
[09:45:30] Step 4/8 — install_fresh.php (column patches)
  done in 1200ms
[09:45:31] Step 5/8 — settings translator
  Settings migration:
    [ren]  email_host -> smtp.host
    [seed] rbac.require_separate_approver = 0
    ...
  done in 600ms
[09:45:32] Step 6/8 — level → role migration
  ... (depends on whether you used migrate_rbac.php previously)
  done in 200ms
[09:45:32] Step 7/8 — smoke test
  Smoke test:
    [ok]   DB reachable
    [ok]   RBAC v2 schema present
    [ok]   every user has at least one grant
    [ok]   Super Admin role has is_super=1
    [ok]   audit_log writes
    [ok]   permissions catalog populated (130 rows)
    [ok]   alias mapping in place (66 aliases)
  SMOKE: PASS (7 checks)
  done in 350ms
[09:45:33] Step 8/8 — postcheck
  === Upgrade verification report — 2026-05-06 09:45:33 ===
  ... (full report printed here — see Step 4)
```

If any step fails, the orchestrator stops and points you at:
- The log file path (everything captured)
- `tools/upgrade/ROLLBACK.md` (rollback procedure for the failure point)

## Step 4: Verify

```
php tools/upgrade/postcheck.php --compare pre.json
```

Output:

```
=== Upgrade verification report — 2026-05-06 09:46:00 ===

Counts (post / pre):
  member                   120  (pre 120)  ✓
  ticket                  4321  (pre 4321)  ✓
  responder                 18  (pre 18)    ✓
  facilities                 7  (pre 7)     ✓
  user                      18  (pre 18)    ✓
  user_roles                18  (pre 0)     +18
  newui_audit_log            5  (pre 0)     +5
  permissions              130  (pre 0)     +130
  roles                      6  (pre 0)     +6
  ...

Schema state:
  RBAC v2 schema present                      ✓
  Migration backup table                      ✓
  Alias column on permissions                 ✓
  is_super flag on roles                      ✓
  Time tracking schema                        ✓
  Auto-approve column                         ✓

Permission matrix:
  users_with_role            18 / 18
  orphan_users                0

Key settings:
  rbac.require_separate_approver  = 0
  rbac.delegation_max_depth       = 1
  rbac.time_entry_auto_approve    = off
  smtp.host                       = mail.example.com
  smtp.from                       = (set)

OVERALL: HEALTHY — upgrade looks complete.
```

The deltas should make sense:
- `member`, `ticket`, `responder`, `facilities`, `user` should match pre-counts exactly.
- `user_roles`, `newui_audit_log`, `permissions`, `roles` are NewUI tables — they go from 0 to populated.
- `orphan_users` should be 0. If not, run `php tools/migrate_rbac.php` and re-run postcheck.

## Step 5: Test the new install

Before cutting over:

1. Open `https://your-host/newui/` in a browser
2. Log in with your existing v3.44 username + password (legacy passwords work — NewUI's verifier handles all 6 hash formats)
3. Verify the dashboard loads, your incidents appear, you can edit a member
4. Try the new RBAC admin UI: **Settings → Roles & Permissions** or directly `/newui/roles.php`

If something looks wrong, the legacy install at `/tickets/` is still untouched. You can keep using it and investigate.

## Cut-over (optional, when you're satisfied)

When you're ready to make NewUI the live site:

```bash
# Stop dispatch traffic if you can
# Rename the legacy webroot
mv /var/www/html/tickets /var/www/html/tickets.bak

# Symlink or move newui into place
ln -s /var/www/newui /var/www/html/tickets
# or: mv /var/www/newui /var/www/html/tickets
```

Or update your web server to serve `/var/www/newui/` at your dispatch URL.

After cutover:

1. Tell users to clear cookies and log in again (sessions are NOT shared between legacy and NewUI)
2. Schedule the expire-grants cron: `0 3 * * * /usr/bin/php /var/www/newui/tools/expire_grants.php` (nightly)
3. Visit `Settings → Roles & Permissions` and verify the role assignments look right

## Common questions

**My passwords didn't work.** Verify the user's `password` column in the DB hasn't been emptied. NewUI supports bcrypt, MD5, MySQL PASSWORD, SHA1, plain, and empty. If your install used a custom hash format, contact support.

**A user can't see anything after login.** They're missing an RBAC grant. As Super Admin, go to `roles.php → User Grants` and grant them a role.

**The legacy URL stopped working.** That's expected if you replaced/symlinked the webroot. To recover the legacy site temporarily, restore the original directory.

**Can I run NewUI and legacy side-by-side forever?** Technically yes — they share the DB and don't conflict. Practically, NewUI changes data in ways legacy doesn't expect (like populating `user_roles` and `newui_audit_log`). Don't run both for production traffic. Pick one as the live site.

**The upgrade said `rbac.require_separate_approver` was seeded — what does that do?** It controls whether an admin can approve their own time entry. Default is `0` (allowed) for volunteer ops. Flip to `1` if you need separation of duties. See `docs/RBAC-GUIDE.md`.

## Rollback

If anything goes wrong, follow `tools/upgrade/ROLLBACK.md`. The TL;DR:

```bash
mysql -u root -p tickets < tools/upgrade/backups/<timestamp>.sql
```

The legacy install at the old path is still there until you rename it in cut-over, so the rollback is "restore DB and switch back to the old webroot."

## Getting help

- Wiki: https://github.com/openises/tickets/wiki
- Issues: https://github.com/openises/tickets/issues
- Attach `tools/upgrade/upgrade-<ts>.log` and the postcheck report to any support request.
