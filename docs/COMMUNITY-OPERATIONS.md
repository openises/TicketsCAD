# Community operations: how an absence stops being silence

**Audience:** the maintainer, and anyone who forks the project and wants the same
safety net. For what a *reporter* should expect, read [`SUPPORT.md`](../SUPPORT.md).

## Why this exists

A one-maintainer project is only as responsive as that person's attention. While
someone was at the keyboard, a new issue usually had a reply within hours; while
nobody was, replies took more than a week and some did not come at all. A
reporter's "still broken" on an issue that had already been *closed* could sit
unread, because nothing looked at closed issues. Acknowledging, noticing and
publishing all depended on a person being present.

So three things are made independent of anyone being present, and every
judgement call stays with the maintainer:

1. **An acknowledgement within minutes**, from a bot that says it is a bot
   (the triage workflow).
2. **A detector that works from state, not events**, so it finds what is
   unanswered even after a missed run or an Actions outage.
3. **A mechanical check that a fix has reached public `main`** before anyone
   says "fixed" (see [Publishing](RELEASE-PROCESS.md)).

The automation finds, drafts and gates. It never decides a feature, never sends
Google Group mail, never publishes or edits a security advisory, never closes a report,
and never speaks as the maintainer.

## What runs where

| Piece | Where it runs | Ships in the public repository |
|---|---|---|
| **Triage bot**: acknowledges and labels each new issue (`.github/workflows/triage.yml`, `tools/triage-issue.php`, wording in `.github/triage/acknowledgement.md`) | Public repository, on `issues: opened` | yes |
| **Labels as code** (`.github/labels.json`, `tools/sync-labels.php`, `.github/workflows/labels.yml`) | Public repository, on a push that changes the file, or by hand | yes |
| **Release notice**: "Included in release vX.Y.Z" on each issue a release names (`.github/workflows/release-notify.yml`, `tools/release-notify.php`) | Public repository, on `release: published` | yes |
| **Status issue** body generator (`tools/status-issue.php`, `.github/status-issue-template.md`) | On demand | yes |
| **Community inbox**: the state-based detector (`tools/community-watch.php`, `.github/workflows/community-watch.yml`) | Private repository, every three hours | no |
| **Sync-lag gate** and **traceable publish** (`tools/sync-lag-check.php`, `tools/publish-sync.sh`) | Development tree, on demand and in `tools/deploy.sh` | no |

The last two rows are described in [`RELEASE-PROCESS.md`](RELEASE-PROCESS.md)
(publishing) and in the maintainer's own notes. They reason about the
private/public split, which is why they are not part of what is published.

## First-time setup

None of it needs a secret, and every step is a read-first, change-second step.

1. **Read the acknowledgement** (`.github/triage/acknowledgement.md`). It is public
   text posted on every new issue; edit the prose before the workflow reaches the
   public repository, or set `TRIAGE_ENABLED` to `false` there first to let it land
   dormant and switch it on when the wording is settled.
2. **Labels.** The labels workflow runs by itself on the push that adds or changes
   `.github/labels.json`. To see what it will do first: `php tools/sync-labels.php
   --repo=<owner>/<repo>` is a dry run.
3. **Variables.** All optional. Set `AWAY_UNTIL` only when you are actually away.
4. **Try it.** Open a test issue from a second account (an issue from the
   maintainer's own account is deliberately skipped), confirm the acknowledgement
   and the `needs triage` label arrive, then close it.
5. **Status issue (optional).** `php tools/status-issue.php` prints the body.
   Read it; `--create` opens it, and pinning an issue is a click in the web page
   (a token cannot do it).
6. **The first release after this** posts "Included in release" on every issue its
   notes name, up to `RELEASE_NOTIFY_MAX`. `--dry-run` shows the plan first:
   `php tools/release-notify.php --event=<release event json> --repo=<owner>/<repo> --dry-run`.

## The away setting

One repository variable, `AWAY_UNTIL`, a date. While it is today or later, the
acknowledgement says plainly that the maintainer is away until that date, what
still works (the security route), and that a personal reply may take longer. The
status issue banner reads the same variable.

```
gh variable set AWAY_UNTIL --repo <owner>/<repo> --body 2026-10-20
gh variable delete AWAY_UNTIL --repo <owner>/<repo>
```

It also works from the repository's settings page in a browser: under
*Secrets and variables*, then *Actions*, then the *Variables* tab. It takes ten
seconds from a phone. A value that is not a real `YYYY-MM-DD` date is ignored
and reported in the workflow log; the bot will never print "away until banana".
The date is inclusive: on the date itself the maintainer is still away.

## Repository variables

Every setting is a repository variable (all optional), read by the tool named in
the last column, and changing it demonstrably changes that tool's output
(`tests/test_triage_issue.php` and its siblings prove each one). An unset or
malformed value falls back to the default and says so.

| Variable | Default | Meaning | Read by |
|---|---|---|---|
| `AWAY_UNTIL` | unset | Maintainer away through this date (`YYYY-MM-DD`) | triage bot, status issue, community inbox header |
| `TRIAGE_ENABLED` | `true` | Kill switch for the acknowledgement bot (`false`, `0`, `no`, `off` turn it off) | `tools/triage-issue.php` |
| `TRIAGE_TARGET_DAYS` | `3` | First-reply target named in the acknowledgement and on the status issue (1 to 30) | `tools/triage-issue.php`, `tools/status-issue.php` |
| `TRIAGE_TARGET_STYLE` | `number` | `number` says "within 3 days"; `qualitative` says "usually within a few days" and names no number | `tools/triage-issue.php`, `tools/status-issue.php` |
| `TRIAGE_SKIP_ASSOCIATIONS` | `OWNER,MEMBER` | `author_association` values that are not acknowledged (the maintainers' own issues) | `tools/triage-issue.php` |
| `TRIAGE_WINDOWS_LABEL` | `windows/iis` | Label added when the install-method answer says Windows | `tools/triage-issue.php` |
| `RELEASE_NOTIFY_ENABLED` | `true` | Kill switch for release notices | `tools/release-notify.php` |
| `RELEASE_NOTIFY_MAX` | `25` | Most issues notified per release (1 to 200); the rest are reported, not notified | `tools/release-notify.php` |
| `RELEASE_NOTIFY_PRERELEASES` | `false` | Also notify for pre-releases | `tools/release-notify.php` |
| `COMMUNITY_AGING_DAYS` | `14` | An acknowledged open item gets a status line at least this often | `tools/community-watch.php`, `tools/status-issue.php` |
| `COMMUNITY_REPOS` | the product and legacy repositories | Repositories the inbox watches (comma list) | `tools/community-watch.php` |
| `COMMUNITY_MAINTAINERS` | the maintainer's login | Logins whose comments count as "the maintainer" (comma list) | `tools/community-watch.php` |
| `COMMUNITY_UNANSWERED_HOURS` | `4` | An open item with no maintainer comment is "unanswered" after this long | `tools/community-watch.php` |
| `COMMUNITY_WAITING_HOURS` | `24` | A reporter's newest comment is "waiting" after this long | `tools/community-watch.php` |
| `COMMUNITY_CLOSED_WINDOW_DAYS` | `60` | Closed or updated issues this recent are still watched for a reporter's follow-up | `tools/community-watch.php` |
| `COMMUNITY_DECISION_DAYS` | `7` | A feature request with no disposition label after this long is listed | `tools/community-watch.php` |
| `COMMUNITY_DECISION_LABELS` | `planned,on hold,wontfix,duplicate,declined` | Labels that count as a disposition | `tools/community-watch.php` |
| `COMMUNITY_NOTIFY_MIN_HOURS` | `24` | At most one notification comment per this many hours | `tools/community-watch.php` |
| `COMMUNITY_RELEASE_FLOOR_DAYS` | `28` | A release older than this, with commits since, becomes an inbox item | `tools/community-watch.php` |
| `COMMUNITY_INBOX_TITLE` | `Community inbox` | Title of the inbox issue | `tools/community-watch.php` |
| `COMMUNITY_INBOX_ASSIGNEE` | the maintainer's login | Assignee when the inbox issue is created | `tools/community-watch.php` |
| `SYNC_WARN_HOURS` | `24` | Public `main` is "behind" once the oldest unpublished commit is this old | `tools/sync-lag-check.php` |
| `SYNC_FAIL_HOURS` | `72` | ...and "far behind" at this | `tools/sync-lag-check.php` |
| `SYNC_PUBLIC_REPO` | the public repository | Which repository the sync check reads when no local clone is given | `tools/sync-lag-check.php` |
| `COMMUNITY_GH_CMD` | `["gh"]` | JSON array: the command prefix used to reach the GitHub CLI (an argument list, never a shell string) | every tool above |

Two more are not repository variables but environment settings of the
development scripts: `DEPLOY_REQUIRE_SYNCED=1` makes `tools/deploy.sh` refuse
when public `main` is behind, and `GH_BIN` / `PUBLISH_SYNC_CI_POLL` configure how
`tools/publish-sync.sh` waits for CI.

## The acknowledgement

The wording is a plain file, `.github/triage/acknowledgement.md`. Edit the
prose there; `{{target}}` is replaced by the target, and the block between
`{{#away}}` and `{{/away}}` appears only while the maintainer is away (with
`{{date}}` filled in). The hidden marker at the end (added by the tool, not the
template) is how a re-run recognises its own comment and does not post twice.

What the comment will never do: speak as a person, promise a fix or a date, or
repeat any text from the issue. The issue title and body are read from the
event file the runner writes and matched against fixed strings; nothing from
them is placed on a command line, in a shell, or in the comment.

Labels added: `needs triage` always; `bug`, `enhancement` or `question` when an
API-created issue has none and its title starts `[Bug]`, `Feature:`, and so on;
the Windows label when the install-method answer contains "Windows";
`live operations` when the form answer to *Is this affecting live operations
right now?* begins "Yes". A label the repository does not define is skipped and
reported (create it with the labels workflow), never sent.

## Labels

Defined in `.github/labels.json`, applied by `tools/sync-labels.php` (a dry run
unless `--apply`; the labels workflow applies on a push that changes the file).
It creates what is missing, corrects drift on labels marked `sync`, leaves
`create-only` labels alone once they exist, and **never deletes or renames** a
label. Meanings:

| Label | Meaning |
|---|---|
| `needs triage` | New and not yet read by a person. The bot adds it; the maintainer removes it. |
| `confirmed` | The maintainer reproduced it, or confirmed it from the code. |
| `live operations` | The reporter says a real, current operation is affected. |
| `planned` | Accepted and intended to be built. Not a promise of a date. |
| `on hold` | Deliberately paused; the thread says why and when it is revisited. |
| `fixed in main` | The fix is in `main`; `git pull` gets it. Applied only after the sync check passes. |
| `needs reporter` | Waiting for the reporter to answer or confirm. |

Whether `planned`, `on hold` and `wontfix` are used, and when, is a maintainer
decision made in conversation; the labels only give a decision somewhere to be
visible. No label is ever closed or removed automatically, and nothing is ever
auto-closed.

## The release notice

When a release is published, the workflow reads the issue numbers the release
notes and the changelog section for that version name (`GH#146`), and comments
once on each: *Included in release vX.Y.Z (link).* A pull request, a draft, a
pre-release (by default), a number that does not exist, and a release already
announced are all skipped. Only issue numbers and a validated tag reach the
comment.

## The status issue

`tools/status-issue.php` renders the body of one issue, "Project status and known
issues", from the repository's own data: latest release, when `main` last
moved, the open bug reports with a status each, the stated target, and the away
banner. It is **print-only by default**; `--create` opens the issue (read what it
prints first, it is public text) and `--apply=N` refreshes it, refusing any issue
that does not carry the managed marker, so a mistyped number cannot overwrite a
human's issue. It publishes nothing by itself.

## If the bot did not comment

1. Is `TRIAGE_ENABLED` set to a falsy value? Is the author's association in
   `TRIAGE_SKIP_ASSOCIATIONS` (the maintainers' own issues are skipped)?
2. Look at the workflow run for that issue. A skipped run says why in one line.
3. A label that does not exist is skipped with a warning; run the labels workflow.
4. GitHub does not replay an event it failed to deliver during an Actions
   outage. That is exactly why the community inbox recomputes from state: the
   issue shows up as *unanswered* on the next run regardless.

## Trust and failure model

- Workflows request the least token scope that works (`issues: write` and
  `contents: read`), run no `pull_request_target`, use no secrets, and keep every
  `${{ }}` expression out of `run:` lines (`tests/test_github_workflows_safety.php`
  enforces all of that on every workflow in the directory).
- Every external program is started from an argument list; bodies travel on
  stdin. Child output goes to temporary files, not pipes, so a chatty or wedged
  child can neither deadlock the tool nor outlive its timeout.
- Best effort by design: a run GitHub never delivers is caught by the inbox, not
  by retrying. A failed *acknowledgement* turns the run red; a missing label or a
  failed label call does not.

## Testing

`php tools/test_all.php` runs the whole set. Each tool runs as a real subprocess
through the real command runner against a recording stand-in for `gh`
(`tests/_fake_gh.php`), so what is exercised is the production code path with only
the network replaced. Nothing can be tested against GitHub Actions itself from
a workstation: the workflow files are validated structurally and by the
safety gate, and the first live run is the real proof.
