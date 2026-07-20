# Contributing translations

Thank you for considering a translation. Multilingual docs make TicketsCAD usable for agencies that don't operate in English.

## Two translation tracks

TicketsCAD has two separate translation surfaces:

1. **In-app UI** — the buttons, labels, and messages a user sees in the running app. Driven by the `t($key, $default)` PHP function + the `captions` database table + per-language JSON files in `captions/`. See [`I18N-GUIDE.md`](../I18N-GUIDE.md). **Editable through the Settings → Languages admin panel** without writing any code.

2. **Documentation** — the `.md` files under `docs/`. This guide is about that track.

The two are independent. You can translate the UI without touching docs, or vice versa.

## Documentation directory layout

```
docs/
  INDEX.md                 ← English (canonical)
  GLOSSARY.md
  FAQ.md
  ...
  locales/
    CONTRIBUTING-TRANSLATIONS.md   ← this file
    de/                            ← German
      README.md
      INDEX.md
      ...
    nl/                            ← Dutch
    fr/                            ← French
    es/                            ← Spanish
    <your-language>/               ← add your own
```

Every translated doc lives under `docs/locales/<lang-code>/` mirroring the structure of the English `docs/` root. Filenames stay the same — `INDEX.md` in German is at `docs/locales/de/INDEX.md`, not `docs/locales/de/INDEX-DE.md`.

## Supported languages

The repo currently ships translation scaffolds for:

| Code | Language | Status |
|---|---|---|
| `en` | English (US) | Canonical (100%) |
| `de` | German | Scaffold only — community needed |
| `nl` | Dutch | Scaffold only — community needed |
| `fr` | French | Scaffold only — community needed |
| `es` | Spanish | Scaffold only — community needed |

We picked these five because the in-app UI ships translation seeds for the same set. Other languages are welcome — see [Adding a new language](#adding-a-new-language) below.

## What to translate first

Priority order. Anything beyond the first three is gravy.

1. **INDEX.md** — the front door. Every reader hits this first. ~150 lines.
2. **GLOSSARY.md** — terminology consistency matters MORE than coverage. Translate the most-used terms first (incident, dispatch, responder, status, mayday) even if you stop there.
3. **FAQ.md** — the most-used reference for daily ops.
4. **INSTALLATION-CHECKLIST.md** — admins setting up new installs.
5. **NEWUI-USER-GUIDE.md** — long-form user reference.
6. **TROUBLESHOOTING.md** — sysadmin reference. Lower priority because admins typically read English when fixing a broken system.

We do NOT recommend translating:

- Spec / architecture docs (`specs/...`) — these are working documents for developers; they change too often
- Phase setup logs — historical artefacts
- The training-scripts — these have spoken-word delivery that doesn't translate cleanly without a complete rewrite (consider recording new videos in your language instead)

## Style conventions

### Stay literal where possible

If the English says "click **New Incident**", the UI button is labelled "New Incident" in English. Your translation should give the user enough to *find* that button in the UI. Either:

- Translate the action ("klicken Sie auf **Neuer Vorfall**") and add the English label in parentheses ("(New Incident)") so users can find it in untranslated installs
- Or: translate the button name only if the UI's caption table has the German translation enrolled (check `captions` table for `nav.new_incident` or similar key)

When in doubt, **include both languages** the first time a UI control appears in your doc.

### Code blocks stay in English

Don't translate command names, file paths, configuration keys, or SQL. They're language-tags inside a code block, not prose.

```bash
# OK — comments translated:
# Update package lists / Paketlisten aktualisieren
sudo apt-get update
```

```bash
# NOT OK — commands translated (won't run!):
sudo apt-erhalten aktualisieren
```

### Glossary terms — pick once and stay consistent

Translate "incident" once, then use that translation everywhere. Don't switch between synonyms. The German might be "Vorfall" or "Einsatz" — pick one, document it in your GLOSSARY.md, stick with it.

### Date and number formats

Use the format your reader expects:

- German / French / Dutch / Spanish: dates as DD.MM.YYYY or DD/MM/YYYY; comma as decimal separator
- US English: dates as YYYY-MM-DD (ISO) or MM/DD/YYYY; period as decimal separator

Inside `code blocks`, keep the original format — those are literal commands.

## Translation status badges

Mark each translated file's status at the top:

```markdown
> **Translation status:** German translation by [your name], updated 2026-06-15. Current with English version of 2026-06-15. If you see English text below, it has not been translated yet.
```

Three categories:

- **Current** — translation matches the latest English revision
- **Behind** — translation is older than the latest English; reader should cross-check
- **In progress** — translation isn't complete

When the English doc updates, the translation goes "behind" until someone updates it. Don't delete out-of-date translations — `behind` is better than missing.

## How to contribute

### For a single doc

1. Fork the repo
2. Copy `docs/INDEX.md` to `docs/locales/<lang>/INDEX.md`
3. Translate prose; leave code blocks in English
4. Add the translation status badge
5. Send a PR with title `docs(<lang>): translate INDEX.md`

### For a whole language

1. Fork the repo
2. Create `docs/locales/<lang>/` with at minimum `README.md` and `INDEX.md`
3. Update `docs/INDEX.md`'s "Multilingual documentation" section to mention your language as available
4. PR with title `docs(<lang>): initial scaffold + INDEX translation`
5. Subsequent PRs add one or two more files at a time

We don't expect any one contributor to translate everything. **Partial translations are welcome and useful.**

## Adding a new language

If you want to start translating into a language not in the current list:

1. Use the [ISO 639-1 code](https://en.wikipedia.org/wiki/List_of_ISO_639-1_codes) (`it` for Italian, `pt` for Portuguese, `zh` for Mandarin, etc.)
2. Create `docs/locales/<code>/README.md` introducing the translation effort and listing files complete + planned
3. Add a row to the language table in `docs/INDEX.md`
4. (Optional) Add the language to the in-app caption registry — see [I18N-GUIDE.md](../I18N-GUIDE.md)

## Machine translation

We don't gatekeep, but please don't submit pure machine-translation output as a finished translation. Use machine translation as a starting point if it helps, but a human should review every sentence for accuracy + tone. Bad translations are worse than missing ones — they mislead readers who trust them.

If you only have time for a machine pass: submit it tagged as **In progress** with a note inviting refinement. That way readers know to cross-check.

## Maintaining a translation

When the English version of a doc updates:

1. The English doc gets a new commit
2. The translated version goes "behind" automatically — readers can see the badge
3. A contributor (you, or someone else) updates the translation to match
4. Update the badge timestamp + version

GitHub Action (planned): when an English `.md` changes, auto-file an issue against each translated copy asking "update?". Not yet implemented; contributions welcome.

## Quality bar

Aim for:

- **Comprehensible by a competent operator** in the target language who is reading carefully.
- **Self-consistent** — same glossary terms throughout.
- **Honest** — if you're not sure of a translation, mark it with a TODO comment + the English original below.

Aim away from:

- Word-for-word translation that produces awkward target-language prose
- Pretending an in-progress translation is complete
- Translating things that genuinely don't translate (e.g. project names, command-line flags)

## Questions

File a GitHub issue with the `docs` and `i18n` labels, or join the discussions tab.

Thank you for making TicketsCAD accessible to your community.
