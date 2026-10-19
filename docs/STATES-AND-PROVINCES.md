# States / Provinces

Every **State** dropdown in TicketsCAD — on incidents, members, units,
facilities, vehicles, constituents, places and warn locations — is filled from
one list. Since GH#153 an administrator can change that list from the
application: pick a built-in set (US states, Canadian provinces, Mexican
states, ...), import your own, edit single entries, and rename the field to
"Province", "Region" or anything else.

Where: **Settings → States / Provinces** (in the *Application — Geographic
Data* group). The page needs the **Manage configuration** permission
(`action.manage_config`, held by Super Admin).

## What a change does — and does not do

- It changes the **choices** the dropdowns offer. Pages already open keep the
  old list until they are reloaded.
- It **never changes a saved record.** An incident saved with `MN` keeps `MN`
  after Minnesota is removed, and its form still shows `MN`. The removed code
  is simply no longer offered for new entries.
- Before anything is removed, the preview tells you how many saved records use
  each code being removed, and where (incidents, members, units, facilities,
  constituents, places, warn locations, organizations, vehicles, regions, major
  incident command posts, service provider dispatches). Records in the
  Wastebasket are counted too, because they can be restored.

## The configuration wizard

1. **Choose.** Either press one of the one-click buttons (*US 48 contiguous +
   DC*, *US 50 + DC*, *US 50 + DC + territories*, *Every US postal code*,
   *Canada*, *US 50 + DC + Canada*, *Mexico*, *US 50 + DC + territories +
   Canada + Mexico*) or tick one or more built-in lists.
2. **Options.** Some lists have extras. The United States: District of
   Columbia (ticked by default), the five inhabited territories, the armed
   forces mail codes, the freely associated states. New Zealand: the Chatham
   Islands Territory. India: the eight union territories (ticked by default).
3. **Replace or add.**
   - *Replace the list* — the list becomes exactly what you chose. Anything
     else is removed; an entry whose code is in your choice keeps its place
     and takes the built-in name.
   - *Add to the list* — keep everything and add what is missing. Codes already
     on the list are skipped (the preview shows them, with the name already
     there).
4. **Preview.** What will be added, removed, renamed and skipped, and the
   saved-record counts for every removal. Nothing has changed yet. *Download
   this selection* gives you the chosen list as a CSV file instead.
5. **Apply**, then optionally **set the field label**. The wizard suggests one
   from your choice: Canada → *Province*, Ireland → *County*, New Zealand →
   *Region*, the US and Canada together → *State or Province*.

If somebody else changes the list between your preview and your Apply, the
Apply is refused and the preview runs again, so you never apply a preview you
did not see.

When two of the lists you chose use the same code for different places (the
United States and Australia both use `WA`), the first list's entry is kept and
the preview names the one that was not added.

## Built-in lists

| List | Entries | Codes and names from |
|------|---------|----------------------|
| United States — 50 states | 50 (+ DC, territories, armed forces, freely associated) | USPS Publication 28, Appendix B |
| United States — 48 contiguous states | 48 (same options) | USPS Publication 28, Appendix B |
| Canada | 13 provinces and territories | Canada Post addressing guidelines |
| Mexico | 32 federal entities | ISO 3166-2:MX |
| Australia | 6 states + 2 territories | ISO 3166-2:AU |
| New Zealand | 16 regions (+ Chatham Islands) | ISO 3166-2:NZ |
| United Kingdom | 4 countries | ISO 3166-2:GB |
| Ireland | 26 counties | ISO 3166-2:IE |
| Germany | 16 Länder (German names) | ISO 3166-2:DE |
| Brazil | 26 states + the Federal District | ISO 3166-2:BR |
| India | 28 states (+ 8 union territories) | ISO 3166-2:IN |

Two deliberate choices: Canada's names are the English forms Canada Post
publishes (*Quebec*, not *Québec*); India's codes are the current ISO codes
(CG, OD, TS, UK since November 2023) with the names as the Government of India
writes them, without the ISO romanisation marks. Change either after applying
if your agency writes them differently — *Edit* on the row.

Each built-in list can be downloaded as CSV from the *Built-in lists as CSV*
card, edited, and imported back.

## Import and export

**Export CSV** downloads the current list. **Import CSV** reads a file (or text
you paste) and shows the same preview as the wizard before anything changes.

The file:

- has the header `code,name` (columns in either order; other columns are
  ignored);
- is UTF-8 — in Excel use *Save As → CSV UTF-8 (Comma delimited)*. A file saved
  in another encoding is refused rather than read wrongly;
- may use `,` or `;` between columns (European Excel), CRLF or LF line ends,
  and may start with a byte-order mark;
- holds at most 2,000 rows and 256 KB.

Every row is checked: a code is 1 to 4 letters, digits or hyphens (stored in
capitals); a name is 1 to 64 characters; a code may appear only once. Each
problem is listed with its line number, and **any** problem blocks the import.

Exports protect spreadsheets: a cell starting with `=`, `+`, `-` or `@` is
written with a leading apostrophe so Excel does not run it as a formula, and
the importer removes that apostrophe again, so an exported file re-imports
unchanged.

## Editing single entries

*Add entry* adds one code and name. *Edit* on a row changes its code or name
(Enter saves, Esc cancels). *Delete* first tells you how many saved records use
the code. Changing a code does not change records saved with the old code; the
confirmation says how many there are.

## The field label

The *Field label* card renames the State fields on every form — *State*,
*Province*, *State or Province*, *Region*, *County* or your own wording (up to
40 characters) — for the language you choose. It writes the caption
`form.state`, the same one Settings → Translations edits; other languages keep
their own translation.

## New Incident address lookup

When the geocoder fills in an address on New Incident, the State field is
matched by name (with or without accents) and then by the ISO 3166-2 code the
geocoder also returns (`CA-QC` → `QC`, `MX-MIC` → `MIC`), which is the code every
built-in list uses. So a Canadian or Mexican address picks the right entry
even when the geocoder spells it differently from your list. If nothing
matches, the field is left as it was.

## An empty list

You may empty the list on purpose; every State dropdown then offers nothing
(saved values still show). The application no longer substitutes the US states
for an empty list — it does so only when the list could not be read at all.

## Behind the scenes

- Table `states_translator` (`id`, `code`, `name`), utf8mb4 with a unique key on
  `code` (`sql/run_gh153_states_translator_utf8mb4.php` converts older installs;
  exact duplicate rows are removed, conflicting ones are reported on this page
  and left for you to resolve).
- A fresh install gets the 50 states + DC from `sql/run_phase84_states_seed.php`,
  which seeds **only** an empty list that nobody has changed through this page
  (setting `states_list_customized`), so running the migrations again never
  puts the US states back.
- Endpoint `api/states.php`; library `inc/states.php`; the built-in data lives
  in one file, `inc/state-presets.php`.
- Every change — single entries, wizard and import applies, exports, the label —
  is written to the audit log (Settings → Audit Log, target *state_province* or
  *caption*).
