# TicketsCAD Command Bar — Cheat Sheet

Press **`/`** anywhere in the app (as long as you're not typing in a text field) to
open the command bar. Type a command name or a short alias, then **Enter** to run
it. If what you typed matches more than one command, a dropdown appears — use
**↑ / ↓** then **Enter**, click, or **Tab** to complete to the highlighted one.
**Esc** closes the bar without doing anything.

You don't have to type the whole word. `/in` is enough for `/incidents` as
long as nothing else starts with `in`.

## Dispatch & workflow

| Command | Aliases | What it does |
|---|---|---|
| `/new` | — | Create a new incident |
| `/incidents` | `/inc` | Focus the Active Incidents widget |
| `/responders` | `/res`, `/resp` | Focus the Responders widget |
| `/units` | `/uni` | Focus the Responders widget (units view) |
| `/facilities` | `/fac` | Focus the Facilities widget |
| `/activity` | `/logs` | Focus the Activity Log widget |
| `/detail` | — | Open detail page for the selected incident |
| `/zello` | `/zel` | Toggle the Zello radio panel |
| `/road` | — | Toggle the road-conditions overlay on the dashboard map |
| `/radio` | — | Open the radio (DMR) widget |

## Navigation — jump to a page

| Command | Aliases | What it does |
|---|---|---|
| `/dashboard` | `/dash`, `/home`, `/sit`, `/situ`, `/situation` | Open the dashboard (situational view) |
| `/bigscreen` | `/wall`, `/fullscreen`, `/eoc` | Open the full-screen situation display (large monitor) |
| `/search` | — | Open the search page |
| `/reports` | — | Open the reports page |
| `/settings` | — | Open the settings / configuration page |
| `/sop` | — | Open the SOP viewer |
| `/help` | — | Open the help page |
| `/roster` | — | Open the personnel roster |
| `/teams` | `/team` | Open the teams page |
| `/schedule` | — | Open the scheduling page |
| `/vehicles` | — | Open the vehicles page |
| `/equipment` | — | Open the equipment page |
| `/roles` | — | Open the roles & permissions admin page |
| `/profile` | — | Open your user profile |
| `/contacts` | `/constituents` | Open the contacts / constituents page |
| `/messages` | `/messaging` | Open internal messaging |
| `/links` | — | Open the external links page |
| `/ics` | `/forms` | Open the ICS forms page |
| `/major` | `/events` | Open the Major Events list |

## Settings deep links

| Command | Aliases | What it does |
|---|---|---|
| `/users` | — | Settings → User Accounts |
| `/audit` | — | Settings → Audit Log |
| `/types` | — | Settings → Incident Types |
| `/organizations` | `/orgs` | Settings → Organizations |
| `/password` | — | Change your password |
| `/training` | — | Settings → Training |
| `/zones` | — | Settings → Alert Zones |

## Unit status — change a unit without opening a modal

```
/s <handle> <status>
/status <handle> <status>      (same thing — /s and /st also work)
```

**Examples**

| You type | What happens |
|---|---|
| `/s M21 av` | Medic 21 → Available |
| `/status E2 disp` | Engine 2 → Dispatched |
| `/s Engine 2 dispatched` | Multi-word unit names work too |
| `/s M4 out of service` | Three-word statuses work too |

The status keyword is read from the END of what you type, so everything before it is treated as the unit handle. Case doesn't matter. Statuses needing extra info (destination, reason) route you to the unit's S-key modal instead. An unrecognized word is still tried against your install's own configured statuses.

## Event Net-Control zone move

```
/z <team> <zone>
```

**Examples**

| You type | What happens |
|---|---|
| `/z alpha 3` | Team Alpha → the zone with code or name "3" |
| `/z echo clear` | Echo's zone assignment is cleared (clear, none, off all work) |

Requires an active event selected on the Net Control board first.

## Net-control check-ins — capture a whole round in one line

```
/net <id> <note> / <id> <note> / <id> <note> ...
```

**Examples**

| You type | What happens |
|---|---|
| `/net 1234 tornado / 3344 hail` | Two check-ins captured in one keystroke |

First word of each entry is the identifier, the rest is the note. Separate entries with /. Opens the situational screen with the check-ins loaded.

## Quick Notes — capture a note from anywhere

```
/log <text>   → capture a timestamped note in one keystroke
/log          → open the notes list to review/file it
```

**Examples**

| You type | What happens |
|---|---|
| `/log KOB reported at 4th and Main` | Note captured instantly, no navigation |

Renamed from the Activity Log widget-focus command (that's /activity now) so this shorter name could go to quick capture instead.

## Unit status shortcuts (case-insensitive)

| Status | Type any of |
|---|---|
| Available | `av`, `avail`, `available` |
| Busy | `busy` |
| Unavailable | `unav`, `unavail`, `unavailable` |
| Dispatched | `disp`, `dispatched`, `dp` |
| Enroute | `en`, `enr`, `enroute` |
| Responding | `resp`, `responding` |
| On Scene | `os`, `onscene`, `on-scene`, `on_scene` |
| Transporting | `tx`, `transp`, `transport`, `transporting` |
| At Facility | `af`, `atfacility`, `at-facility` |
| In Quarters | `iq`, `inquarters`, `in-quarters` |
| Out of Service | `oos` |

## Keys once the bar is open

| Key | Does |
|---|---|
| **Enter** | Run the highlighted / typed command |
| **Tab** | Complete to the highlighted (or first) suggestion |
| **↑ / ↓** | Move the highlight in the dropdown |
| **Esc** | Close the bar, do nothing |

---

*Generated by `php tools/gen_command_bar_cheat_sheet.php` from the live command registry in `assets/js/command-bar.js` — if a command here doesn't match what your install actually does, re-run the generator; if it still doesn't match, the code is the source of truth, please open an issue.*
