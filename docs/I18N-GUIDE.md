# NewUI i18n / Translations Guide

NewUI v4 ships an internationalization (i18n) engine that lets administrators translate every UI label into any language without touching code. This guide covers both the **administrator** workflow (using the Translations panel) and the **developer** workflow (retrofitting a page template to support translation).

Phase shipped: `phase-08-i18n-2026-06`. See `specs/phase-08-i18n-2026-06/` for the design history.

---

## What's shipped today

| Capability | Status |
|---|---|
| Translation function `t('key', 'English default')` for PHP templates | ✅ |
| `captions_i18n` database table with per-language values | ✅ |
| REST API for read / write / import / export of captions | ✅ |
| **Settings → App Preferences → Translations** admin panel | ✅ |
| **Language switcher** in the navbar (visible when ≥2 languages are configured) | ✅ |
| `inc/navbar.php` retrofitted — every nav label is translatable | ✅ |
| `inc/config-sidebar.php` retrofitted — section headers + key tabs | ✅ |
| Seed German (`de`) translations for navbar + sidebar | ✅ |
| Other 60+ page templates (dashboard, new-incident, settings panels' inner content, roster, etc.) | ⚠️ **NOT YET** — retrofit incrementally |
| Per-user persistent language preference (DB column) | ⚠️ Not yet — Phase 8 uses `$_SESSION['lang']` only |
| Date / number / currency locale formatting | ⚠️ Out of scope |
| Right-to-left (Arabic, Hebrew, …) layout | ⚠️ Out of scope |

Switching language affects the navbar and config sidebar immediately. Other pages will still show English labels until they're retrofitted by follow-up commits.

---

## For administrators — using the Translations panel

### Opening the panel

1. Log in as a user with `action.manage_config` permission (Super Admin, Org Admin, or any role with that permission).
2. Click **Config** in the navbar.
3. In the left sidebar, expand **App Preferences** and click **Translations**.

### Editing existing translations

The table shows one row per caption key, with one column per configured language. Cells highlighted in pale yellow are untranslated (showing `—`).

- **Click any cell** → it becomes an input field.
- Type the translation.
- **Press Enter** (or click anywhere else) → saves immediately.
- **Press Esc** → cancels without saving.

Saves are atomic per cell — there is no "Save All" button.

### Adding a new caption

Click **Add Caption**. You'll be prompted for:

1. **Key** — dot-separated identifier (e.g. `nav.menu.foo`, `btn.confirm`). 2–128 characters, alphanumeric + dots + dashes + underscores. This is what the page template will reference via `t('key', '...')`.
2. **English value** — the default text that displays when no other translation is available.
3. **Category** — optional grouping (e.g. `nav.menu`, `btn`, `form`, `status`). Used by the category filter; doesn't affect rendering.

The new caption gets an English row immediately. Other-language columns start empty; translators fill them in by clicking the cells.

### Adding a new language

Click **Add Language**, then enter a 2–8 character language code (ISO 639-1 codes like `de`, `fr`, `es`, or `pt-br` for regional variants).

The new language appears as a column. The first time you add a language, it seeds a placeholder row so the rest of the system recognizes it; you can then fill in translations for any caption.

After adding a language, **reload the page** to see it appear in the navbar's language switcher.

### Importing / exporting translations

For bulk translation work, exchange JSON files with translators:

1. **Export** — click the Export button. Downloads `captions-YYYY-MM-DD.json` containing all captions in all languages.
2. **Hand the file to a translator** — they edit the `value` field for the language they're translating, then send it back.
3. **Import** — click the Import button, choose the JSON file. Upserts on `(caption_key, lang)` — existing keys are updated, new ones are created, rows not in the file are untouched.

The expected JSON shape (both formats are accepted):

```json
{
  "export": [
    {"caption_key": "nav.menu.units", "lang": "de", "value": "Einheiten", "category": "nav.menu"},
    {"caption_key": "btn.save",       "lang": "de", "value": "Speichern", "category": "button"}
  ]
}
```

Or just a bare array:

```json
[
  {"caption_key": "nav.menu.units", "lang": "fr", "value": "Unités", "category": "nav.menu"}
]
```

### How users switch languages

Users see a small globe icon in the navbar (between the org switcher and the user menu) when ≥2 languages are configured. They click it, pick a language, and the page reloads in that language.

The choice persists for the rest of their session. Logout clears it (per-user persistent preference is a future enhancement).

---

## For developers — retrofitting a page template

When the navbar shows German but the rest of a page is still English, the page hasn't been retrofitted yet. Conversion is mechanical and contained — a single page is usually a 20–40 minute commit.

### The pattern

For every hardcoded English label in the template:

**Before:**
```php
<h2>Open Incidents</h2>
<button class="btn btn-primary">Save</button>
```

**After:**
```php
<h2><?php echo e(t('dashboard.open_incidents', 'Open Incidents')); ?></h2>
<button class="btn btn-primary"><?php echo e(t('btn.save', 'Save')); ?></button>
```

Inside helper-call arguments (which don't render through `<?php echo ... ?>`):

```php
// Before
nav_btn('reports.php', 'bar-chart-line', 'Reports', 'reports', $active_page);
// After
nav_btn('reports.php', 'bar-chart-line', t('nav.menu.reports', 'Reports'), 'reports', $active_page);
```

Make sure the page **requires** `inc/i18n.php` at the top (or relies on something else that already does — `inc/navbar.php` and `inc/config-sidebar.php` both do it themselves, and they're included on most pages).

After modifying the template, add seed rows for every new key to `sql/captions.sql` and the active migration runner, run the migration on every environment that needs the new keys, and translate them via the admin panel.

### Key-naming convention

Use dot-separated, lowercase namespaces. The first segment is the category:

| Pattern | Examples |
|---|---|
| `nav.menu.*` | `nav.menu.situation`, `nav.menu.full_screen` |
| `nav.user.*` | `nav.user.profile`, `nav.user.log_out` |
| `nav.title.*` | `nav.title.language`, `nav.title.notifications` |
| `sidebar.section.*` | `sidebar.section.system`, `sidebar.section.app_prefs` |
| `sidebar.tab.*` | `sidebar.tab.user_accounts` |
| `btn.*` | `btn.save`, `btn.cancel`, `btn.delete` |
| `form.*` | `form.address`, `form.city`, `form.notes` |
| `status.*` | `status.open`, `status.closed` |
| `dashboard.*` | new page-specific captions go in their own namespace |

Reuse common namespaces (`btn`, `form`, `status`) when the semantics match. A "Save" button should always be `btn.save` — don't create `dashboard.save` and `roster.save` for the same word.

### Always pass an English default

`t('key.path', 'English Default')` — the second argument is shown when:
- No `captions_i18n` row exists for that key in the current language, AND
- No row exists for it in English (the fallback layer), AND
- No legacy `captions` table override exists

This means a fresh-out-of-the-box page renders English even if no captions were seeded. **Never pass a key without a default** — it produces literal output like `dashboard.open_incidents` on screen.

### JavaScript strings

The `t()` helper is a PHP function and only works server-side. For strings emitted into JavaScript code, two options:

**Option A — emit translated strings at template render time:**

```php
<script>
var SAVED_MSG = <?php echo json_encode(t('msg.saved', 'Saved.')); ?>;
</script>
```

**Option B — emit a CAPTIONS object once, look it up in JS:**

```php
<script>
var CAPTIONS = <?php echo t_js(); ?>;  // emits all captions for the current lang
</script>
<script src="assets/js/my-page.js"></script>
```

And in JS: `var msg = CAPTIONS['msg.saved'] || 'Saved.';`

`t_js()` is defined in `inc/i18n.php` and returns a JSON object of every caption in the current language merged with English fallbacks.

### Always escape on render

`t()` returns the raw stored value. Wrap it with `e()` (NewUI's `htmlspecialchars` wrapper) when rendering into HTML, matching the rest of the codebase:

```php
<?php echo e(t('form.name', 'Name')); ?>
```

The exception: when passing into a PHP function that itself handles output (like `nav_btn()`), the function is responsible for escaping. The navbar's `nav_btn()` already uses `htmlspecialchars()` on the label.

---

## API reference

### `t($key, $default)`

Translate one key. Lookup order:

1. `captions_i18n` row for `(caption_key=$key, lang=current_session_lang)`
2. `captions_i18n` row for `(caption_key=$key, lang='en')` (English fallback)
3. Legacy `captions.repl` row where `captions.capt = $key` or `$default`
4. The `$default` argument itself

### `i18n_lang()`

Returns the current request's language code. Priority:

1. `$_SESSION['lang']` if set and sanitized
2. First language tag from the `Accept-Language` HTTP header
3. `'en'`

### `i18n_available_langs()`

Returns a sorted array of language codes that have ≥1 row in `captions_i18n`. Used by `inc/navbar.php` to decide whether to render the switcher.

### `t_js()`

Returns a JSON-encoded object of every caption key for the current language, with English fallback. Suitable for embedding in a `<script>` tag.

### `POST /api/set-language.php`

Body: `{lang: "de", csrf_token: "..."}`

Validates `lang` (regex whitelist + existence in `captions_i18n`), writes `$_SESSION['lang']`, returns `{success: true, lang, reload: true}`. Audited as `i18n.set_language`.

### `GET /api/captions.php`

Returns `{captions: [...], languages: [...], total: N}`. Optional query params: `?lang=de` (filter), `?search=key_or_value` (search).

### `POST /api/captions.php`

Body: `{action: "save|delete|import|export", ...}`. Admin-only (`action.manage_config` permission).

- `save` — upsert by `(caption_key, lang)`. Body: `{caption_key, lang, value, category, csrf_token}`. Optional `id` to update by ID.
- `delete` — `{id, csrf_token}`.
- `import` — `{captions: [...], csrf_token}` — bulk upsert.
- `export` — returns `{export: [...], count, version}`. Optional `lang` filter.

---

## Known limitations

- **No translation memory.** Translators see only the source/target value per cell; they don't see suggestions from similar past translations.
- **No fuzzy matching.** If the English source changes, existing translations don't get flagged for review.
- **No machine translation pre-fill.** All translations are entered manually (or via JSON import). Integrating DeepL or Google Translate is a possible future enhancement.
- **JS string strings outside `CAPTIONS`** aren't translatable until the page is retrofitted with `t_js()`. The navbar's JS, the dashboard widget labels, etc. are still hardcoded English.
- **Dynamic data (incident types, member names, custom fields, etc.) are not translated.** Those live in user-owned DB tables, not in the caption system. Translating them would require per-row translation tables — a major schema change deferred indefinitely.
- **ICS form numbers are intentionally NOT translated.** "ICS-213" is a FEMA-standardized identifier.

---

## Contributing translations

The project welcomes contributed translations. To translate the existing captions into your language:

1. Ask an administrator to export the current captions (`Settings → Translations → Export`). You'll get a JSON file.
2. Edit the file in any text editor. For each row in your target language, fill in the `value` field. If a row for your language doesn't exist yet, add it:
   ```json
   {"caption_key": "nav.menu.units", "lang": "fr", "value": "Unités", "category": "nav.menu"}
   ```
3. Send the file back. The admin imports it.

For commitments to the project repo (so future installations get your language by default), open a PR adding your translations to `sql/run_phase08_i18n.php` in the same `$seeds` array pattern used for English + German.

See https://github.com/openises/TicketsCAD for the PR process.
