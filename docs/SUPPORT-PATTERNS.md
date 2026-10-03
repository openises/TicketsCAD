# Support-pattern index — GitHub / mailing-list triage

For `/watch` and any manual triage of `openises/TicketsCAD` issues or the
`open-source-cad` Google Group. Check a new report against this index FIRST — if the
symptom matches a row, run the named diagnostic tool before reading any code. Most
reports that reach a maintainer are a KNOWN shape; treat "read the whole subsystem
from scratch" as the fallback, not the default.

This is a triage aid, not a bug database — it doesn't track individual issue
numbers or their current status (GitHub is the source of truth for that). It tracks
**recurring symptom → tool → root-cause-class** mappings so the diagnostic step
never has to be re-derived.

## How to use this during `/watch`

1. Match the reporter's symptom description to a row below.
2. Run the named tool/page. It usually names the exact cause, or narrows it to one
   of the listed classes.
3. If the diagnostic confirms a known class, the fix is usually small and localized
   — go straight to the file it names.
4. If nothing matches, or the diagnostic itself is inconclusive, this is genuinely
   novel — fall back to full investigation, and consider adding a new row here once
   it's understood (this index should grow with every real triage).

## Symptom → tool → cause map

| Symptom (as a reporter describes it) | Run this first | Common root-cause classes seen before |
|---|---|---|
| "Push notifications don't arrive" / "no callout on my phone" | `php tools/push_diagnose.php --simulate` (recipient resolution) then `--fire` (live end-to-end through the real audit→push pipeline) | (1) `vendor/minishlink/web-push` not installed — `composer install` never run; (2) VAPID keys unset; (3) the routing/broker stack not loaded on the calling code path (`push_fire()` self-loads `inc/broker.php` now, but a new call site could still miss it); (4) recipient resolution itself — UNIT vs PERSONNEL responder↔member linkage, see `tests/test_push_recipient_resolution.php`. |
| "Bed/capacity count is wrong" / "beds didn't decrement" | `php tools/bed_auto_diagnose.php` | (1) `assigns.rec_facility_id` vs `ticket.rec_facility` — which one is actually written depends on `un_status.extra_data_target`, and the default config an admin gets may not be the one that works (see CLAUDE.md's GH #20 saga); (2) facility bed-automation mode not enabled (`facilities.bed_auto_mode`). |
| "Zello widget shows offline/flapping" / "channel won't connect" | The **Diagnostics** page (navbar → user account menu, NOT under Settings — `diagnostics.php`), Zello card (`diagZelloCard`) | (1) a real `on_channel_status` error/error_type being discarded and shown as generic "offline" (GH #21-class); (2) reconnect-backoff counter reset by the wrong success event, causing rapid re-auth and a 429 cool-off (see `browser-audio-to-voice-service` skill's symptom→cause map for the full audio-pipeline list — sample-rate/frame-duration/DTX issues manifest as "sounds garbled/chipmunk/half-speed", not connection failures). |
| "Real-time updates don't show up" / "I have to refresh to see new incidents" | The **Diagnostics** page (navbar → user account menu — same page as above), SSE card (`diagSse`) | (1) `EventBus`/`audio-alerts` scripts only loaded per-page instead of globally via `navbar.php`; (2) `api/stream.php` needs `set_time_limit(360)` — PHP's 120s default kills the stream and EventBus gives up reconnecting; (3) a genuinely down web server (check `docs/CI-ENVIRONMENT.md`'s note that `@requires-http` tests can mask this class in CI). |
| "Fresh install fails" / "migration error" / "column not found" after an upgrade | `php tools/check-schema.php --repair` (re-applies migrations, does not delete data); `php sql/run_migrations.php` for the raw log | (1) genuine schema drift (restored from an old backup, a table dropped during crash recovery); (2) the two-permission-systems trap if the report is about access/authorization rather than a raw SQL error — check `user.level` is not involved anywhere in the reporter's install age. |
| "Something's broken, not sure what" / vague "the app doesn't work right" | **Settings → System Health** (`status.php` — `tools/check-health.php`'s web equivalent) — covers scheduled jobs, backup verification, web-exposure, dependency/SBOM currency in one screen | Ask the reporter to paste this page's output before investigating further — it's the fastest way to rule out environment-class problems (missing cron/systemd timer, `vendor/` never installed, exposed directories) before assuming a code bug. |
| "Message from Telegram/Slack didn't reach the incident" (Phase 134-class) | Confirm the sender's Telegram/Slack identifier is on file (Roster → member → Comm/Location IDs) AND that Settings → Telegram/Slack → "Poll for inbound messages" is actually turned on (`telegram_poll_inbound`/`slack_poll_inbound`, both off by default) | (1) polling never enabled — this is the single most likely cause on any report of this shape, check it before anything else; (2) sender's handle recorded with the wrong case or including an `@` where the stored value shouldn't have one; (3) the message resolved but the sender has no OPEN assignment right now — it still should have reached general chat via the Model 1 fallback route, so confirm that happened before assuming resolution is broken. |
| "I did a `git pull` and the fix isn't there" / "still broken after you said it was fixed" | Ask for the bug form's commit line (`git log -1 --format='%h %cd'`), then compare it with the fix commit. On the maintainer side, `php tools/sync-lag-check.php --issue=N` (development tree only) says whether every publishable commit naming the issue is in public `main`. | (1) The fix was announced before public `main` had it (the check above was skipped, or the sync had not run): the reporter is RIGHT, say nothing about repositories, keep the item open until the sync lands; (2) the reporter's commit is older than the fix; (3) a stale browser or CDN copy of a script or stylesheet (assets carry an mtime version now, so a hard refresh settles it); (4) a Docker image or ZIP install only moves on a TAGGED release, so `main` having the fix is not the same as their install having it. Never reply "stale version, hard-refresh" before checking (1). |
| "Nobody has replied to me" / "is this project still alive?" | The acknowledgement bot's comment on the issue (it is posted within minutes), then `SUPPORT.md`; for a Google Group post, check for a Gmail draft | A new issue should already carry the bot's acknowledgement and the `needs triage` label. If not: `TRIAGE_ENABLED` off, an Actions outage (the event was lost; the community inbox finds the issue from state on its next run), or the author's association is in `TRIAGE_SKIP_ASSOCIATIONS`. A comment on a CLOSED issue is easy to miss: look at closed issues updated recently, not only open ones. |
| A reporter comments on an issue that is already closed | Read it as you would a new report: it may be a confirmation ("verified, closing") or a regression | A confirmation needs no reply. Anything that asks something or says the problem persists gets a reply within the same 3-day target, and the issue is reopened if the problem is real. "Closed" is not "unreadable". |
| Security/exposure report (a URL that shouldn't answer, a file that shouldn't be servable) | Do NOT probe the reporter's own live install with anything beyond what they already showed. Reproduce locally against a throwaway/test install only. | Check `docs/PITFALLS-INDEX.md`'s "Web exposure / hardening" section first — several of these have shipped fixes with specific known-still-open edge cases (e.g. `hiddenSegments` collisions, IIS role-service gaps). This class of report gets escalated to Eric before any public reply — see the standing security-disclosure handling in the `/watch` skill. |

## Reply structure (match this, don't freelance the shape)

Every fix reply this project has shipped follows the same skeleton — copy the shape,
not the words:

1. **Name what was wrong**, in one sentence, in plain language a non-developer
   reporter can follow.
2. **Cite the exact location** — `file:line` and, once shipped, the commit SHA.
3. **State the root cause**, not just the symptom that was fixed — if the report
   matched one of the classes above, say which one.
4. **Say what verification was actually done** ("regression test added, verified it
   fails against the reverted bug and passes against the fix") — never claim a fix
   works without having run something.
5. If nothing shipped yet (needs Eric's decision, needs the reporter's own
   confirmation, or is a genuine feature request) — say so plainly and name what
   specifically is being waited on, not a vague "we'll look into it."

**Before any comment says a fix is available:** run the sync check for that issue
(`php tools/sync-lag-check.php --issue=N`, development tree only) and post the
sentence only if it exits 0. If it does not, a plain "confirmed and being worked
on" is allowed; any statement that says the fix can be had is not. This is the
one rule of this section that is enforced by a command rather than by care.

**Channel-specific formatting:**
- **GitHub issue comment** — normal markdown is fine, code fences and `file:line`
  links render correctly there.
- **Mailing list / email** — plain-text only. No markdown, no asterisks, no
  backticks, no code fences, no `[text](url)` link syntax — write the URL bare.
