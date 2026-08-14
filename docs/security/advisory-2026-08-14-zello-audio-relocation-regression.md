# Zello recordings were moved into another site's document root on Windows by round 2 of GHSA-x9x6-w4fg-pmcc

- **Severity:** Critical
- **Affected:** Windows hosts (IIS and XAMPP) that had recordings under the
  in-tree `cache/zello-audio/` directory and ran the round-2 relocation
  migration. Also any install, on any platform, that used round 2's
  sibling-of-app-root directory long enough to accumulate recordings there.
- **Not affected:** Linux, Docker and macOS installs — `dirname(NEWUI_ROOT)`
  is not published on those layouts, so round 2 fixed the problem it claimed
  to fix, for them.
- **Patched in:** this fix (round 2 of GHSA-x9x6-w4fg-pmcc, 2026-08-14).
- **Reported by:** Ron Jones ([@rjonesbsink](https://github.com/rjonesbsink)).
- **Related:** GHSA-x9x6-w4fg-pmcc (round 1 — recordings inside `cache/`,
  inside the web root); the identical layout mistake in
  advisory-2026-08-03-windows-backup-regression.md (BACKUP_DIR) and
  advisory-2026-08-03-fe-keys-dir.md (FE_KEYS_DIR).

## Summary

GHSA-x9x6-w4fg-pmcc described Zello voice-message recordings being served
from `cache/zello-audio/`, inside the web root, and round 2 fixed it by
moving them to `dirname(NEWUI_ROOT) . '/zello-audio'` — "a sibling of the
app root".

That is the third time this exact relocation has been written in this
codebase, and the third time it inverted on Windows. On a standard
Windows/IIS layout, `C:\inetpub\wwwroot\TicketsV4`'s sibling is
`C:\inetpub\wwwroot` itself — the physical path of **Default Web Site,
bound to port 80**. XAMPP behaves the same way.

Ron reported it directly, having reported round 1 originally: upgrading
4.2.14 → 4.2.17 and re-running the relocation migration moved 210 recordings
from a local, unfirewalled port (his app ran on 8089) to
`C:\inetpub\wwwroot\zello-audio` — reachable, complete and unauthenticated,
on port 80, which **does** have an inbound firewall rule on his host. The fix
made his exposure worse, not better: reachable from the network where it had
only been reachable from the box itself before.

**Upgrading — and running the migration the release notes told operators
to run — is what caused the exposure.** No misconfiguration was required.

## Why nothing reported it

Settings → System Health → Web exposure probes only the URL this install
answers on. Its own disclosure text says so:

> "Probed http://localhost:8089 only. Other web sites on this machine,
> other ports, and anything published through a reverse proxy are outside
> what this can see — including directories that hold this install's own
> files."

That directory was reachable on a port the check does not look at. The
disclosure was correct and complete; nothing about the check itself needs
to change, and this advisory does not ask it to scan other ports — that
would be a different, riskier tool. The finding is that relocating
something to a place the check *admits* it cannot see is a fact worth
weighing at the point the destination is chosen, not just a caveat to
disclose afterward.

## Impact

Complete Zello voice-message recordings — the actual audio content of radio
traffic on this install's Zello channels — readable by anyone who requests
them, unauthenticated, from a site most operators do not think of as
"theirs" (the machine's Default Web Site).

## Am I affected?

You are affected if **all** of these are true:

1. Your server is **Windows** — IIS or XAMPP — and
2. You installed TicketsCAD inside a published directory, which is the
   documented and ordinary arrangement (`C:\inetpub\wwwroot\...`,
   `C:\xampp\htdocs\...`), and
3. You ran `sql/run_zello_audio_relocate.php` (directly, or via the
   migration runner) any time before this fix.

### Check it

**Do not test this by requesting the folder.** A `403`/`404` there proves
nothing about whether a file inside it is served.

1. Get a real recording filename — `SELECT media_url FROM zello_messages
   WHERE message_type='voice' LIMIT 1;`, or any `.ogg`/`.webm` filename you
   see in the directory below.
2. Request that file from **every site and port your server publishes**,
   not only the one TicketsCAD runs on. On IIS, `appcmd list site` lists
   them with their physical paths.

```
http://localhost:<your-app-port>/zello-audio/<file>.ogg   (should still work — this install's own site)
http://localhost/<file>.ogg                                 (the check — should now be 404/403)
```

A `200` on the second request, for a filename you know is a real recording,
means it is being served by another site.

## Fix it now

Upgrade. The fix moves NEW recordings to `%ProgramData%\TicketsCAD\
zello-audio` on Windows (not a site root under IIS, XAMPP or nginx) and
writes deny rules (web.config + .htaccess) beside recordings **wherever
they are found** — the old in-tree location, round 2's sibling location,
and the new default — so a directory-placement mistake and a missing deny
file both have to happen before anything leaks, not either alone. Existing
recordings are moved forward automatically; nothing is deleted, and nothing
already found is left unfenced even if the move fails.

If you cannot upgrade immediately, harden the exposed directory by hand —
drop a `web.config` with `<system.webServer><security><requestFiltering>
<fileExtensions allowUnlisted="false" /></requestFiltering></security>
<directoryBrowse enabled="false" /></system.webServer>` into
`C:\inetpub\wwwroot\zello-audio` (see `docs/WEB-SERVER-HARDENING.md` for the
full block) and confirm with the check above that it now returns 404/403.

## What changed in this fix

1. **The Windows default is `%ProgramData%\TicketsCAD\zello-audio`**,
   matching where BACKUP_DIR and FE_KEYS_DIR already moved to after their
   own rounds of this exact mistake. The POSIX default is unchanged — it
   was correct there. The platform and the application root are both
   parameters of the function that computes the location, so every
   reported layout is directly assertable from any CI machine — a test
   that can only see its own platform's answer is how this shipped three
   times.
2. **Every directory that could hold a recording is hardened
   unconditionally**, the same discipline FE_KEYS_DIR already uses: a
   recording has no legitimate reachable-over-HTTP state, so there is no
   case where skipping the fence is correct. This now covers the new
   default, round 2's old sibling location, and round 1's original
   in-tree location — not just wherever the code's author happened to be
   picturing.
3. **The relocation migration moves from BOTH earlier locations**, not
   just the original in-tree one, so an install that already ran round
   2's migration (like the reporter's) has its files carried forward to
   the now-safe location rather than left stranded at the location that
   was just proven exposed.
4. **The same sweep found four more directories with the identical
   unfenced `dirname(NEWUI_ROOT)` pattern** — the weather-proxy circuit
   breaker's state file, the DMR/radio bridge health-state file, the
   geocode lookup cache, and the map tile cache. None had a reporter
   looking at exactly that directory; all four are fixed the same way in
   this change, and two of them (the geocode cache and the tile cache)
   turned out to also be missing from `docker-compose.yml`'s volumes
   entirely — a separate, unrelated gap this sweep's test coverage caught
   in passing.
5. **This is now backed by a standard, not just precedent.** Microsoft's
   own Request Filtering documentation describes `allowUnlisted="false"`
   as the recognized deny-by-default model this project uses, and the CIS
   Microsoft IIS 10 Benchmark treats request filtering as one of its core
   hardening categories. `<hiddenSegments>` — the other mechanism Microsoft
   documents for this — is deliberately NOT used here: this project found
   in an earlier pass (2026-08-02) that a hidden-segment name collides
   with any legitimately-used directory of the same name elsewhere in the
   tree and can take an entire site down. The scoped, per-directory
   web.config this project already used is the more robust choice of the
   two documented approaches, not an invented one.

## Credit

Found and reported by Ron Jones ([@rjonesbsink](https://github.com/rjonesbsink)),
who found round 1 of this same advisory, then upgraded to the release that
fixed it, ran the documented migration, and tested what actually happened on
his own Windows server rather than assuming the fix had worked. He measured
which port his firewall actually admits, applied his own deny-all web.config
as an interim mitigation, and reported the exact reasoning that made the fix
wrong before asking for a change.

To report a security concern in TicketsCAD, see `SECURITY.md`. Private
vulnerability reporting is enabled on this repository. Please report
privately rather than in a public issue, and allow time for a fix before
disclosing.
