# Getting help

TicketsCAD is free software, maintained by one person with help from the
people who run it. This page says where to ask, what reply to expect, and what
the project does not promise, so that a quiet week is never a mystery.

## Where to ask

| You want to… | Go here |
|---|---|
| Report something that is not working | **New issue → Bug report** |
| Suggest something it should do | **New issue → Feature request** |
| Ask how to install, upgrade or set something up, and have the question tracked | **New issue → Question** |
| Ask a quick or general question, or "has anyone done this?" | The [Google Group](https://groups.google.com/g/open-source-cad). No GitHub account is needed to read it. |
| Report a security problem | **Privately**, never in a public issue: see [`SECURITY.md`](SECURITY.md) |

Please leave real names, addresses, phone numbers, patient information and
passwords or keys out of anything you post. Issues and the Group are public.

## What to expect

- **Within minutes, on every new issue:** an automatic acknowledgement, posted
  by a bot and labelled as one, so you know your report arrived. It is not a
  review, and it says so. If the maintainer has said they are away, it says
  until when.
- **A reply from the maintainer, aimed at within 3 days:** the report is
  confirmed, or more information is needed, or the answer is already in the
  documentation, or it has been received and a decision is pending. That is a
  target, not a guarantee, and a reply is not a promise of a fix date.
- **No promised fix time.** An acknowledged report that is still open gets a
  short status line at least every 14 days, so silence does not have to mean
  "forgotten".
- **When a fix exists:** it is announced on the issue once it is available in
  `main` (`git pull` gets it), and again when a tagged release includes it.
  The version number alone does not say whether a fix is present, so the bug
  form asks which commit you are on (`git log -1 --format='%h %cd'`).
- **A pull request:** acknowledged within the same 3 days. "Accepted" and
  "merged" can be different events; [`CONTRIBUTING.md`](CONTRIBUTING.md) explains.
- **Security reports** have their own, firmer commitment: acknowledged within
  3 business days, with a remediation timeline within 10. See
  [`SECURITY.md`](SECURITY.md). They are always handled first.

## What the project does not promise

There is no support contract, no guaranteed response time, and no second
maintainer standing by; [`GOVERNANCE.md`](GOVERNANCE.md) is plain about that, and
it applies here. TicketsCAD is used by volunteer groups, and those groups should
plan accordingly: it is not a substitute for a tested fallback during a real
callout, and a bug report is not an emergency line.

## Before you file: the checks that answer most reports

1. **Settings, System Health** shows missing scheduled jobs, exposed folders,
   an uninstalled dependency and many other environment problems in one screen.
   If anything is red, paste it into the report.
2. **Which commit are you on?** `git log -1 --format='%h %cd'` in your install
   folder. If a fix was announced, this tells us in one line whether you have it.
3. **The documentation index** ([`docs/INDEX.md`](docs/INDEX.md)) and the
   [troubleshooting guide](docs/TROUBLESHOOTING.md) cover installing, upgrading,
   Docker and the usual failures.

## When the maintainer is away

The acknowledgement says so, with a date, and the security route keeps working.
Nothing about that changes what you should do: file the report, and it will be
read when the maintainer is back.
