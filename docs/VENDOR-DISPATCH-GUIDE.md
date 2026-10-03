# Towing and roadside dispatch (service providers and rotation lists)

**For:** dispatchers who call a tow truck, locksmith or roadside service from an incident, and the administrators who keep the list of companies and the order they are called in.
**Added:** GitHub issue #148 (Phase 155). **Off by default on every install.**

## What this is, and what it is not

A **service provider** is an outside company your dispatchers *call* on someone else's behalf: a tow company, a locksmith, a roadside-assistance service. It is **not** a unit on the board, not a facility and not a constituent. It never appears on the unit board, in PAR roll calls or in your response-time statistics, and it does not need a login.

The software does three things:

1. keeps a **list of companies** and one or more **rotation lists** (for example "County tow rotation" or "Lockout, north end");
2. **suggests who is next** when a dispatcher chooses Tow, Lockout, Jumpstart or Tire Change on an incident, and lets the dispatcher record what happened (called, accepted with an ETA, declined, no answer, on scene, completed, gone on arrival);
3. keeps a **permanent record** of every call and its outcome so that, afterwards, you can show who was offered what, in what order, and why an out-of-order call was made.

It **records and suggests**. It does not send anything to the company automatically (no text, no email, no call). Your agency, county or municipality owns compliance with its own towing ordinance: rotation rules differ from place to place, which is why the behaviour below is settings, not hard-coded.

## Turning it on

1. Open **Settings > Resources > Service Providers (Towing)**. You need the permission *Manage Towing / Roadside Vendors* (Super Admin and Org Admin have it).
2. **Settings** tab (a **Super Admin** does this step: the settings and the service types apply to every agency on the install, so an Org Admin sees them read-only): tick **Enable towing / roadside dispatch** and Save. Until you do, nothing in the app changes: no button, no card, no script, no request.
3. The same tab shows a short checklist: at least one company, at least one rotation list, and at least one list with companies on it. Dispatchers do not strictly need a list (see "A company that is not on any list"), but the suggestion feature does.

The dispatch button and card appear on **every incident type** (a traffic stop, a crash, a disabled-vehicle assist, or a plain tow incident you created yourself), for roles that hold *Dispatch Towing / Roadside Vendor* (Super Admin, Org Admin and Dispatcher do by default; Operator, Read-Only and Field Unit do not).

## Setting it up

### Service types

**Service Types** tab. Four ship by default: **Tow**, **Lockout**, **Jumpstart**, **Tire Change**. You can rename them, add more (Heavy tow, Winch-out, Fuel delivery), reorder them and deactivate them. A type that **asks for a destination** (Tow) shows the destination field on the dispatch dialog; the others do not. The server drops a destination for a type that does not need one even if one is sent. A type that has been used cannot be deleted; deactivate it.

### Companies

**Companies** tab, **New company**: name, contact person, phone, alternate phone, service area, hours or availability note, notes, and an optional **yard** (a facility that becomes the default destination for that company). A company's **services are not set here**: they are worked out from the rotation lists it sits on, so there is nowhere to keep two copies in step.

* **Suspend** a company until a date and time, with a reason. It stays on its lists but is skipped, and dispatchers see "suspended until ...". Lift the suspension at any time.
* **Retire** a company to take it out of every queue. Its history is kept.
* **Delete** is only offered for a company that nothing has ever referred to (a typing mistake). Once a company appears in the history it can only be retired.
* **Organization.** Each company belongs to one organization (the one you were working in) or, for a Super Admin, to "All agencies". The organization is fixed when the company is created. An Org Admin can change only their own organization's companies. A company shared with "All agencies" can be changed only by a Super Admin.

### Rotation lists

**Rotation Lists** tab, **New list**: a name, the **service** it serves, an optional area description and an optional **mode** (see below). Open a list to see its companies in order, the **computed next-up** and each company's last turn, and to add or remove companies.

* **Default list.** Mark one list per (organization, service) as the default. The dispatch dialog preselects it. If there is more than one list for a service and none is the default, the dispatcher chooses.
* **A new company goes to the back.** A company added to a list that already has history joins at the **back** of the rotation. A list with no history is simply in the order you add companies.
* **Move to end.** The only reordering fairness allows. It needs a **reason**, is written to the ledger and the audit trail, and puts the company behind everyone else. There is deliberately **no "move to the front"**: a one-off preference is an *override* on the call itself, which is recorded with its reason.
* **Up and down arrows** work on a list in **strict order** or **manual** mode, and on a round-robin list that has no history yet (to arrange the starting order). On a **round-robin list with history** the order follows the ledger, so the arrows are disabled. On a strict-order or manual list that **already has history**, a move asks for a **reason** and is written to the ledger as an *Order changed* entry (the audit log alone can be purged, the ledger is never purged); so is switching such a list's mode, which would otherwise be a way round the rule.
* **Remove** takes a company off a list without erasing anything. Re-adding it puts it at the back.
* **Retire** hides a list from dispatchers. **Delete** is only possible for a list that was never used.

### Modes

| Mode | Who is next |
|---|---|
| **Round robin** (default) | The company called **longest ago** goes first. A company that has never been called goes before any that have. |
| **Strict order** | The list order is the order, whatever the history. |
| **Manual** | The screen shows the list but **no company is marked next up**; the dispatcher chooses. Every manual pick counts as a normal rotation call. |

The install-wide mode is a setting; a list can override it. A company that has already been tried on a particular dispatch (declined, no answer, unavailable, withdrew, or being called right now) is skipped as "next" **for that dispatch only**.

## Dispatching a tow

On the incident page, click **Tow / Roadside** (button in the header, or **New** on the **Towing / Roadside** card). The button and card appear only once the server has confirmed you may dispatch for that incident; a view-only shared-in user never sees them.

1. **Service needed** is the one required choice. The last choice is remembered in this browser.
2. **Rotation list.** Shown only when more than one list serves that service. With none, you see "No rotation list for this service" and can still log a call to any company below.
3. **Who to call.** Each row shows position, company (contact, hours, service area), number, and a status: **NEXT UP**, "waiting since 09:14", "calling...", "declined", "no answer", "unavailable", "suspended until 08:00" or "not eligible". Press **Log call**. The call is **recorded first**; if click-to-dial is enabled the number is dialled second.
   * If the rotation moved while your screen was open (another dispatcher called the same company a moment ago) you are told who is next now, **nothing is recorded and nothing is dialled**, and the list redraws.
4. **Calls awaiting an outcome.** After a call, the company appears here with **Accepted** (asks for an ETA in minutes), **Declined**, **No answer**, **Unavailable**. Record what happened so the history is complete; a call with no outcome is flagged "awaiting an outcome" on the card.
5. **Picking a company that is not next.** If the pick skips an eligible next-up you are asked for a reason (preset choices: the earlier company did not answer; owner or driver requested this company; closest truck; special equipment needed; other). An agency can turn this off, require the reason, or forbid out-of-order calls altogether (settings below). An out-of-order call **does not use up the chosen company's turn** and does not cost the skipped company its place.
6. **Another company.** Open it to pick a company you already have on file that is not on the list, or to **type a name and phone number** for a company that is on no list at all. If the company you pick is on a rotation list for that service, the pick is judged against **that list** (so naming a company without choosing its list cannot be used to skip the override rules). Tick **The driver or owner requested this company** when that is the reason; that is recorded as such, never uses a turn, and shows in the history's per-company counts (*owner requests*). It is the one deliberate exception to the out-of-order rule, because the law in many places lets the owner choose.
7. **Vehicle and details.** Vehicle (year, make, model, colour), plate and plate state, reason for the call, notes. The pickup location is read from the incident.
8. **Destination** (Tow only). Pick the company's yard, a facility you have on file (an impound lot, a repair shop), or **Enter an address** to type one. A typed address never blocks the dispatch.
9. **Read to the driver.** A ready-made sheet: pickup address, cross street, vehicle, plate, destination, callback number and the **reference** (for example `26-0123-T1`). **Copy** puts it on the clipboard. The callback number field is remembered in this browser only.

Every call and outcome is also written to the **incident log** as a plain sentence ("Tow ref 26-0123-T1: called Anderson Towing (next up) 14:02", "...declined", "...Bergstrom Wrecker accepted, ETA 20 min", "...completed"), so the log and any ICS-214 built from it read as dispatchers expect.

### Managing a dispatch

Open **Manage** on a card row. You can mark **On scene**, **Completed**, **Company withdrew** (returns the dispatch to open so you can call another company), **Gone on arrival**, or **Cancel this dispatch**; update the **ETA**; add a **note**; fix the vehicle or destination details; and read the **timeline**. Status moves are checked: for example a finished dispatch cannot go back to "on scene".

* A new call is refused on a **closed incident** (reopen it first); outcomes and status changes are always accepted, so a tow that arrives after the incident closed can still be recorded.
* **Void.** A mistake is corrected with a **void**, never an edit: the void is a new line naming the one it cancels, a reason is required, and **both stay in the history**. You can void your own entry within 15 minutes; a supervisor (who holds *Manage Towing / Roadside Vendors*) can void any entry at any time. A voided call no longer uses a turn.

### A company that is not on any list

Nothing needs configuring. Open **Another company**, type the company name and number, and **Log call**. It is recorded with how it was chosen ("not on a list"), shows in the history, and never affects any rotation.

## How "who is next" is worked out

Nothing stores a "last dispatched" time that could drift. Every call is a line in an **append-only ledger** (no line is ever edited or deleted by the application), and "who is next" is **derived from that ledger** every time, so the answer always agrees with the record. A company's *last turn* is the newest ledger line that used one of its turns and has not been voided.

Whether a call uses a turn is stamped on the ledger line **when it is written**, so changing a setting later never rewrites history:

| Setting `vendor_advance_rule` | A call to the next company | If it declines or does not answer |
|---|---|---|
| **any_offer** (default) | Uses its turn at once | It has already used it; the next call goes to the next company |
| **accepted_only** | Uses no turn | It keeps its place; **only an accepted call** uses the turn |

**Example (any_offer).** Companies A, B, C. Tow from the rotation suggests A. A declines (A's turn is used). The dispatcher calls B, who accepts with a 20-minute ETA. The **next** tow, on any incident, starts at **C**. After C, everyone has had a turn, so it wraps to A.

Under *accepted_only*, if two dispatchers press the button at the same instant both calls may be recorded (nobody has used a turn yet); under *any_offer* exactly one wins and the other is told the rotation moved.

## History and export

**History** tab: every call and outcome you are allowed to see, newest first. Filter by list, company, date range, or **overrides only**. The **Per company** table counts calls, accepted, declined, no answer, unavailable, overrides and owner requests (voided entries are excluded). **Download CSV** gives the same lines oldest first, one row per ledger entry, including how each call was chosen, who the system believed was next at that instant, the reason, who recorded it, from which address, and whether it was voided. The CSV is safe to open in a spreadsheet (a value that starts with `=`, `+`, `-` or `@` is written with a leading apostrophe), and each download is recorded in the audit log.

History is **scoped by organization**: an organization's supervisors see the lists and incidents their organization owns.

## Settings reference

All on the **Settings** tab of the Service Providers page; written only by that page and **only by a Super Admin** (each value is checked against its allowed list, and only the values you change are sent), read through code that falls back to the default if a stored value is not one of the allowed ones.

| Setting | Default | Allowed | Effect |
|---|---|---|---|
| `vendor_dispatch_enabled` | `0` (off) | `0`, `1` | Shows or hides the button, card, dialog, stylesheet and script on every incident page; turns the API on or off |
| `vendor_rotation_mode` | `round_robin` | `round_robin`, `strict_order`, `manual` | The default mode for every list (a list may override it) |
| `vendor_advance_rule` | `any_offer` | `any_offer`, `accepted_only` | What uses up a company's turn (table above) |
| `vendor_allow_override` | `1` | `0`, `1` | Whether a dispatcher may call a company that is not next. `0` refuses it |
| `vendor_override_requires_reason` | `1` | `0`, `1` | Whether an out-of-order call that skips an eligible next-up needs a reason |
| `phone_click_to_call` | `off` | `off`, `tel_link`, `widget` | `off`: the number is plain text with a **Log call** button (you dial yourself). `tel_link`: a **Call** button (and the number itself) records the call, then opens this device's phone app. `widget`: records the call, then dials from the browser phone |

`phone_click_to_call` is a shared click-to-dial setting. The dialog works completely with `off` (the default), so you can start without a phone integration. With `widget`, whether a call to an outside number is allowed is decided by your phone system's own rules, not by this feature.

## Who can do what

| Permission | Default | Lets you |
|---|---|---|
| `action.dispatch_vendor` | Super Admin, Org Admin, Dispatcher | Use the dispatch button, record calls and outcomes, update details, void your own recent entries |
| `action.manage_vendors` | Super Admin, Org Admin | Manage companies, lists, service types and settings; void any entry at any time; export the history |

Within `action.manage_vendors`, the **service types** and the **settings** are install-wide and are changed by a **Super Admin only**; an Org Admin manages their own organization's companies and lists. (Otherwise one agency's manager could switch the feature off, or rename "Tow", for every other agency.)

`action.manage_vendors` is deliberately **not** available to Dispatcher: changing the rotation order is ordinance-sensitive. (It is a "tier 1" permission, so a role below Org Admin cannot be given it; that is intentional and protects against the privilege-leak class this project has had before.) An install that wants dispatchers not to use the feature can revoke `action.dispatch_vendor` from Dispatcher in **Roles & Permissions**.

## A tow company that has its own logins

The rotation list is for companies you **call**. If a tow company actually logs into your TicketsCAD, model it as an **organization** and use **Cross-Org Ticket Routing** to share the right incidents with it automatically. See [Cross-org ticket sharing](CROSS-ORG-TICKET-SHARING.md). That is separate from, and needs nothing from, this feature.

## Privacy and records

* **Plates and vehicle descriptions are personal data.** They are kept on the dispatch (not in the ledger, which holds only company names and numbers, the actor and the reason). There is **no retention rule** for them yet: if your policy requires one, handle it as you would other incident data.
* **The ledger is never purged by the application.** The audit log has its own retention (Settings > Audit Retention) and can be purged; the ledger is the durable record. A site that wants database-level tamper resistance can remove `UPDATE` and `DELETE` rights on the `vendor_dispatch_ledger` table for the application's database account: the application never needs either.
* Each dispatch keeps a **snapshot** of the incident number, the service name and every company's name and number as they were when used, so renaming a company or purging an incident never changes the record.
* All changes are in the audit log under category `vendor` (companies, lists, members, service types, settings with before and after, every dispatch event, and history exports).

## Troubleshooting

* **No Tow / Roadside button.** The feature is off (Settings tab), or your role lacks *Dispatch Towing / Roadside Vendor*, or you can see this incident only as a view-only shared-in user, or the database update has not been run (`php sql/run_migrations.php`; the Status page's "Database schema vs this version" row reports it).
* **"The rotation just moved. Nothing was recorded."** Another dispatcher called the company a moment ago. Read who is next now and press the button again.
* **A company will not accept a call.** It is retired, or suspended (the driver or owner can still ask for a suspended company by name), or it has already been called on this dispatch (record that call's outcome first).
* **"A reason is required".** The call skips an eligible next-up and your agency requires a reason.
* **The up and down arrows are greyed out.** The list is round robin with history. Use **Move to end**.

## For developers

* Tables: `vendor_service_types`, `vendor_providers`, `vendor_rotation_lists`, `vendor_rotation_members`, `vendor_dispatches` (header, a cache written in the same transaction as the ledger line and proven equal to the ledger-derived status), `vendor_dispatch_ledger` (append-only). None changes an existing table. Migration: `sql/run_gh148_vendor_dispatch.php` (idempotent, verifies its own outcome, exits non-zero on a missing result); captions: `sql/run_gh148_vendor_captions.php`.
* Library: `inc/vendor-dispatch.php` (pure ordering and status functions, the queue, the writers) and `inc/vendor-admin-write.php`. Endpoints: `api/vendor-dispatch.php` (`action.dispatch_vendor`) and `api/vendor-admin.php` (`action.manage_vendors`). Scripts: `assets/js/vendor-dispatch.js` (dialog and card), `assets/js/vendor-admin.js`. Page: `service-providers-admin.php`.
* Concurrency: an offer takes `SELECT ... FOR UPDATE` on the rotation list row as the first statement of a READ COMMITTED transaction (skipped only where the binary log is `STATEMENT`, which refuses writes under it), so two dispatchers are serialised per list; the loser gets `409 queue_changed` with the fresh queue. It then locks the provider row it names (lock order is always list, then provider, the same as deleting a company), so a delete cannot slip in between the checks and the ledger insert. Incident note, audit row and SSE run after commit.
* Clocks: every elapsed-time decision (the 15-minute void window, "suspended until") uses the database's own clock (`vendor_db_now_ts()`), never PHP's `time()`, because the ledger is stamped with the database's `NOW()`.
* Live updates: SSE event `vendor:dispatch` (`{ticket_id, dispatch_id, status}`), registered in `assets/js/event-bus.js`. It is published to the incident's owning organization and to any organization holding an **assist**-tier share of it (a view-only recipient cannot read dispatches, so it is not told); an incident with no owning organization uses the ordinary per-incident audience, which `api/stream.php` limits to holders of `action.dispatch_vendor`.
* The accepted entry uses the turn exactly when the offer it answers was a rotation offer that did not (so changing `vendor_advance_rule` while a call is in flight can neither use two turns nor none).
* Numbers in the dialog carry `data-dial` and `data-dial-ctx` (`{"target_type":"vendor_provider","target_id":N,"ticket_id":N}`) so a click-to-dial module can upgrade them.
* Tests: `tests/test_vendor_*.php` and `tests/test_gh148_reporter_workflow.php`.
