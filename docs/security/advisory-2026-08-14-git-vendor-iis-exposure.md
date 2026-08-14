# .git/ and vendor/ were reachable over HTTP on IIS (continuation of GHSA-rrp6-pqhj-w5wj)

- **Severity:** Critical
- **Affected:** Windows/IIS installs where `.git/` exists inside the served
  tree (a `git clone` install, or a ZIP install later converted per
  `docs/SWITCH-FROM-ZIP-TO-GIT.md`) and/or `vendor/` exists (any install that
  has run `composer install`).
- **Not affected:** Apache and nginx installs — both already deny `.git/` and
  `vendor/` (root `.htaccess`; `docs/nginx/ticketscad-hardening.conf`).
- **Patched in:** this fix, 2026-08-14.
- **Reported by:** Ron Jones ([@rjonesbsink](https://github.com/rjonesbsink)),
  by email rather than a public issue — the exact vulnerable URLs were named
  in the report and nothing was public yet.
- **Related:** GHSA-rrp6-pqhj-w5wj (the original "web root is the application
  root" finding — `backups/`, `sql/`, `tools/`, `inc/`), which named four
  directories; `.git/` and `vendor/` were not among them and were not
  protected for IIS by anything this project shipped.

## Summary

GHSA-rrp6-pqhj-w5wj established that TicketsCAD's documented install puts
the web root at the application root, so every directory in the tree is
served unless something denies it — and that IIS never reads `.htaccess`,
so an IIS install needs its own file per directory. That advisory's fix
shipped `sql/web.config` and `tools/web.config`; the install docs told
operators to copy the same file to `backups/` and `inc/` by hand.

Ron measured what was still missing. On v4.2.18, IIS 10, a stock git-clone
install:

```
GET /.git/config                       200, 614 bytes
GET /.git/HEAD                         200, 21 bytes
GET /.git/refs/heads/main              200, 41 bytes
GET /.git/objects/info/packs           200, 160 bytes
GET /.git/index                        200, 135,752 bytes
GET /vendor/composer/installed.json    200, 86,752 bytes
```

`.git/` being reachable at all is enough to clone the repository and its
full history over plain HTTP (`.git/config` names the remote, `.git/HEAD` +
`.git/refs/` + `.git/objects/` are the whole object graph). `vendor/
composer/installed.json` lists every third-party PHP package this install
runs and its exact installed version — a ready-made list to check against
public CVE databases.

He also compared how many of the sensitive directories each web server's
shipped configuration actually protects: 12 for Apache's `.htaccess`, 12 for
the documented Caddy snippet, 4 for what IIS shipped (`sql/`, `tools/`, plus
the two documented-as-manual `backups/` and `inc/`). IIS was not following a
different policy — it was following a much shorter list.

## Why `.git/` and `vendor/` needed a different fix than the other six

Every other directory this advisory closes (`apache/`, `coordination/`,
`drafts/`, `inc/`, `specs/`, `tests/`, `services/`) is a normal, git-tracked
part of the repository — a `web.config` dropped next to it ships the same
way `sql/web.config` always has, and arrives with a `git pull`.

`.git/` and `vendor/` cannot work that way:

- **`.git/` is git's own internal directory.** Nothing inside it exists in
  any commit — there is no way to make a file appear there via `git clone`,
  because `.git/` is what `git clone` *creates*, not content the clone
  populates from history.
- **`vendor/` is excluded by `.gitignore`'s `/vendor/` directory pattern.**
  Git's own documented behaviour: once a parent directory is excluded, a
  file inside it cannot be re-included even with a `!` negation. Ron's own
  email identified this exact constraint independently.

Ron's proposed workaround was a **root-level `web.config`** with
root-anchored URL Rewrite rules (`^\.git(/|$)`, `^vendor(/|$)`) — deliberately
anchored to avoid the `hiddenSegments` collision this project already hit
once (`assets/vendor/` broke site-wide when a `vendor` hidden-segment rule
matched the name anywhere in the path, not just at the root). It is a
legitimate, tested technique, and he verified `assets/vendor/bootstrap/…`
kept working under it.

It was not used. `tests/test_iis_webconfig_syntax.php` has carried an
explicit rule since 2026-08-02 — no `web.config` at the repository root —
specifically because a site-wide rule is where a mistake (in a hidden
segment, or in anything else) has the largest possible blast radius; every
other rule in this project is scoped to the one directory it protects, so a
mistake in any single file can only ever break that directory, never the
whole site. Given this project's own history of shipping a first-draft IIS
config that was subtly wrong (see `sql/web.config`'s docblock — three
independent defects across two earlier attempts), a root file was judged
the wrong place to add a new mechanism, however carefully tested.

**What shipped instead:** `served_dir_harden()` (`inc/served-dir.php`) — the
same runtime helper already protecting `keys/` and `backups/` — is now also
called for `.git/` and `vendor/`, wired into `inc/navbar.php` so it fires on
the very next page load after a `git clone` + `composer install`, with no
manual step required. It writes the identical Request-Filtering
`web.config` shape every other directory in this advisory uses, scoped to
just that one directory, and is idempotent and best-effort (never blocks a
page load if the write fails).

## Impact

The complete git repository and commit history downloadable, unauthenticated,
by anyone who requests the right paths — including any commit that has ever
existed, not just the current tree. Separately, an exact, matchable list of
every Composer dependency and version this install runs.

## Am I affected?

You are affected if **all** of these are true:

1. Your server is **IIS** (Windows), and
2. `.git/` and/or `vendor/` exist inside your served tree — true for the
   documented install (`git clone` directly into the web root, then
   `composer install`).

### Check it

```
GET http://your-site/.git/HEAD
GET http://your-site/vendor/composer/installed.json
```

A `200` on either means it is exposed. `404` (substatus **404.7** in the IIS
log) means it is denied.

## Fix it now

Upgrade — `git pull`, then load any page in a browser once (that page load
is what triggers `served_dir_harden()` for `.git/` and `vendor/`; every
other directory in this advisory is fixed by the pull alone). Confirm with
the checks above, or on **Settings → System Health**, which now probes
`.git/HEAD` and `vendor/composer/installed.json` by name.

If you cannot upgrade immediately: drop the standard four-line
Request-Filtering `web.config` (see `docs/WEB-SERVER-HARDENING.md`) into
`.git\` and `vendor\` by hand. Foreign files inside `.git\` are not touched
by git's own housekeeping (`git gc`/`git prune` only manage git's own
object/ref/pack data), so this is safe to do directly.

## What changed in this fix

1. **Six directories gained a shipped, git-tracked `web.config`:** `apache/`,
   `coordination/`, `drafts/`, `inc/`, `specs/`, `tests/` — the same
   Request-Filtering shape as `sql/web.config`/`tools/web.config`.
2. **`services/` gained a shipped `web.config` too, with two narrower
   overrides underneath it** (`services/meshtastic/web.config`,
   `services/meshcore/web.config`) that keep the two Mesh Console bridge
   scripts downloadable via ordinary IIS configuration inheritance —
   `tests/test_iis_webconfig_syntax.php` was extended to validate this
   "denies everything except a declared, narrow allow-list" shape
   explicitly, both ways, the same rigor every other file in this project
   gets.
3. **`.git/` and `vendor/` are hardened at runtime**, via
   `served_dir_harden()` calls added to `inc/navbar.php` — no manual step,
   fires on the next page load.
4. **Settings → System Health probes `.git/HEAD` and
   `vendor/composer/installed.json` by name**, matching the existing rule
   that a probe must ask for a real file, never just the directory.
5. **`inc/health-check.php`'s stale remedy text** ("IIS: add the hidden
   segments") was corrected — hiddenSegments was rejected as a mechanism in
   this project over a year ago for exactly the collision risk described
   above, and the health-check page was still recommending it.

## Test coverage

`tests/test_iis_webconfig_syntax.php` — extended to auto-discover and
validate all nine new/changed `web.config` files (it already scans the
whole tree, so no per-file registration was needed) plus the two narrow
`services/meshtastic|meshcore` exceptions, checked both ways (the declared
extension IS served, everything else still is not). `tests/
test_git_vendor_iis_hardening.php` — new; drives the real
`served_dir_harden()` against synthetic `.git`/`vendor` directories, confirms
`inc/navbar.php` actually calls it with `force=true` for both, and confirms
the real files exist on disk on this install right now.

## Acknowledgment

Reported and diagnosed by Ron Jones ([@rjonesbsink](https://github.com/rjonesbsink)),
who also independently tested a working root-web.config alternative before
reporting — the anchored-regex approach he verified is sound IIS practice,
and the choice not to use it here is about this project's own blast-radius
policy, not a flaw in what he tested.
